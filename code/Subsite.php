<?php
/**
 * A dynamically created subdomain. SiteTree objects can now belong to a subdomain.
 * You can simulate subsite access without creating subdomains by appending ?SubsiteID=<ID> to the request.
 *
 * @package subsites
 */
class Subsite extends DataObject implements PermissionProvider {

	/**
	 * @var boolean $disable_subsite_filter If enabled, bypasses the query decoration
	 * to limit DataObject::get*() calls to a specific subsite. Useful for debugging.
	 */
	static $disable_subsite_filter = false;

	static $default_sort = "\"Title\"";

	/**
	 * @var boolean $use_domain Checks for valid domain in addition to subdomain
	 * when searching for a matching page with {@link getSubsiteIDForDomain()}.
	 * By default, only the subdomain has to match.
	 */
	static $use_domain = false;

	static $db = array(
		'Title' => 'Varchar(255)',
		'RedirectURL' => 'Varchar(255)',
		'DefaultSite' => 'Boolean',
		'Theme' => 'Varchar',

		// Used to hide unfinished/private subsites from public view.
		// If unset, will default to
		'IsPublic' => 'Boolean'
	);

	static $has_one = array(
	);

	static $defaults = array(
		'IsPublic' => 1,
	);

	/**
	 * @var Subsite $cached_subsite Internal cache used by {@link currentSubsite()}.
	 */
	protected static $cached_subsite = null;

	/**
	 * @var array $allowed_themes Numeric array of all themes which are allowed to be selected for all subsites.
	 * Corresponds to subfolder names within the /themes folder. By default, all themes contained in this folder
	 * are listed.
	 */
	protected static $allowed_themes = array();

	static function set_allowed_domains($domain){
		user_error('Subsite::set_allowed_domains() is deprecated; it is no longer necessary '
			. 'because users can now enter any domain name', E_USER_NOTICE);
	}

	static function set_allowed_themes($themes) {
		self::$allowed_themes = $themes;
	}

	/**
	 * Return the themes that can be used with this subsite, as an array of themecode => description
	 */
	function allowedThemes() {
		if($themes = $this->stat('allowed_themes')) {
			return ArrayLib::valuekey($themes);
		} else {
			$themes = array();
			if(is_dir('../themes/')) {
				foreach(scandir('../themes/') as $theme) {
					if($theme[0] == '.') continue;
					$theme = strtok($theme,'_');
					$themes[$theme] = $theme;
				}
				ksort($themes);
			}
			return $themes;
		}
	}

	/**
	 * Return the domain of this site
	 *
	 * @return string Domain name including subdomain (without protocol prefix)
	 */
	function domain() {
		if($this->ID) {
			$domains = DataObject::get("SubsiteDomain", "SubsiteID = $this->ID", "IsPrimary DESC",
				"", 1);
			if($domains) {
				$domain = $domains->First()->Domain;
				// If there are wildcards in the primary domain (not recommended), make some
				// educated guesses about what to replace them with
				$domain = preg_replace("/\\.\\*\$/",".$_SERVER[HTTP_HOST]", $domain);
				$domain = preg_replace("/^\\*\\./","subsite.", $domain);
				$domain = str_replace('.www.','.', $domain);
				return $domain;
			}
		}
	}

	function absoluteBaseURL() {
		return "http://" . $this->domain() . Director::baseURL();
	}

	/**
	 * Show the configuration fields for each subsite
	 */
	function getCMSFields() {
		$domainTable = new TableField("Domains", "SubsiteDomain", 
			array("Domain" => "Domain (use * as a wildcard)", "IsPrimary" => "Primary domain?"), 
			array("Domain" => "TextField", "IsPrimary" => "CheckboxField"), 
			null, "SubsiteDomain.SubsiteID", $this->ID);
			
		$domainTable->setExtraData(array(
			'SubsiteID' => $this->ID ? $this->ID : '$RecordID',
		));

		$fields = new FieldSet(
			new TabSet('Root',
				new Tab('Configuration',
					new HeaderField($this->getClassName() . ' configuration', 2),
					new TextField('Title', 'Name of subsite:', $this->Title),
					
					new HeaderField("Domains for this subsite"),
					$domainTable,
					// new TextField('RedirectURL', 'Redirect to URL', $this->RedirectURL),
					new CheckboxField('DefaultSite', 'Default site', $this->DefaultSite),
					new CheckboxField('IsPublic', 'Enable public access', $this->IsPublic),

					new DropdownField('Theme','Theme', $this->allowedThemes(), $this->Theme)
				)
			),
			new HiddenField('ID', '', $this->ID),
			new HiddenField('IsSubsite', '', 1)
		);

// This code needs to be updated to reference the new SS 2.0.3 theme system
/*		if($themes = SSViewer::getThemes(false))
			$fields->addFieldsToTab('Root.Configuration', new DropdownField('Theme', 'Theme:', $themes, $this->Theme));
*/

		$this->extend('updateCMSFields', $fields);
		return $fields;
	}

	/**
	 * @todo getClassName is redundant, already stored as a database field?
	 */
	function getClassName() {
		return $this->class;
	}

	function getCMSActions() {
		return new FieldSet(
            new FormAction('callPageMethod', "Create copy", null, 'adminDuplicate')
		);
	}

	function adminDuplicate() {
		$newItem = $this->duplicate();
		$JS_title = Convert::raw2js($this->Title);
		return <<<JS
			statusMessage('Created a copy of $JS_title', 'good');
			$('Form_EditForm').loadURLFromServer('admin/subsites/show/$newItem->ID');
JS;
	}

	/**
	 * Gets the subsite currently set in the session.
	 *
	 * @uses ControllerSubsites->controllerAugmentInit()
	 *
	 * @param boolean $cache
	 * @return Subsite
	 */
	static function currentSubsite($cache = true) {
		if(!self::$cached_subsite || !$cache) self::$cached_subsite = DataObject::get_by_id('Subsite', self::currentSubsiteID());
		return self::$cached_subsite;
	}

	/**
	 * This function gets the current subsite ID from the session. It used in the backend so Ajax requests
	 * use the correct subsite. The frontend handles subsites differently. It calls getSubsiteIDForDomain
	 * directly from ModelAsController::getNestedController. Only gets Subsite instances which have their
	 * {@link IsPublic} flag set to TRUE.
	 *
	 * You can simulate subsite access without creating subdomains by appending ?SubsiteID=<ID> to the request.
	 *
	 * @todo Pass $request object from controller so we don't have to rely on $_REQUEST
	 *
	 * @param boolean $cache
	 * @return int ID of the current subsite instance
	 */
	static function currentSubsiteID($cache = true) {
		if(isset($_REQUEST['SubsiteID'])) {
			$id = (int)$_REQUEST['SubsiteID'];
		} else {
			$id = Session::get('SubsiteID');
		}

		if(!isset($id) || $id === NULL) {
			$id = self::getSubsiteIDForDomain($cache);
			Session::set('SubsiteID', $id);
		}

		return (int)$id;
	}

	/**
	 * @todo Object::create() shoudln't be overloaded with different parameters.
	 */
	static function create($name) {
		$newSubsite = Object::create('Subsite');
		$newSubsite->Title = $name;
		$newSubsite->Subdomain = str_replace(' ', '-', preg_replace('/[^0-9A-Za-z\s]/', '', strtolower(trim($name))));
		$newSubsite->write();
		$newSubsite->createInitialRecords();
		return $newSubsite;
	}

	/**
	 * Switch to another subsite.
	 *
	 * @param int|Subsite $subsite Either the ID of the subsite, or the subsite object itself
	 */
	static function changeSubsite($subsite) {
		if(is_object($subsite)) $subsiteID = $subsite->ID;
		else $subsiteID = $subsite;

		Session::set('SubsiteID', $subsiteID);

		// And clear caches
		self::$cached_subsite = NULL ;
		Permission::flush_permission_cache() ;
	}

	/**
	 * Make this subsite the current one
	 */
	public function activate() {
		Subsite::changeSubsite($this);
	}

	/**
	 * @todo Possible security issue, don't grant edit permissions to everybody.
	 */
	function canEdit() {
		return true;
	}

	/**
	 * Get a matching subsite for the given host, or for the current HTTP_HOST.
	 * 
	 * @param $host The host to find the subsite for.  If not specified, $_SERVER['HTTP_HOST']
	 * is used.
	 *
	 * @return int Subsite ID
	 */
	static function getSubsiteIDForDomain($host = null) {
		if($host == null) $host = $_SERVER['HTTP_HOST'];
		
		$host = str_replace('www.','',$host);
		$SQL_host = Convert::raw2sql($host);

		$matchingDomains = DataObject::get("SubsiteDomain", "'$SQL_host' LIKE replace({$q}SubsiteDomain{$q}.{$q}Domain{$q},'*','%')",
			"{$q}IsPrimary{$q} DESC", "INNER JOIN {$q}Subsite{$q} ON {$q}Subsite{$q}.{$q}ID{$q} = {$q}SubsiteDomain{$q}.{$q}SubsiteID{$q} AND
			{$q}Subsite{$q}.{$q}IsPublic{$q}");
		
		if($matchingDomains) {
			$subsiteIDs = array_unique($matchingDomains->column('SubsiteID'));
			if(sizeof($subsiteIDs) > 1) user_error("Multiple subsites match '$host'", E_USER_WARNING);
			return $subsiteIDs[0];
		}
	}

	function getMembersByPermission($permissionCodes = array('ADMIN')){
		if(!is_array($permissionCodes))
			user_error('Permissions must be passed to Subsite::getMembersByPermission as an array', E_USER_ERROR);
		$SQL_permissionCodes = Convert::raw2sql($permissionCodes);

		$SQL_permissionCodes = join("','", $SQL_permissionCodes);

		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		return DataObject::get(
			'Member',
			"{$q}Group{$q}.{$q}SubsiteID{$q} = $this->ID AND {$q}Permission{$q}.{$q}Code{$q} IN ('$SQL_permissionCodes')",
			'',
			"LEFT JOIN {$q}Group_Members{$q} ON {$q}Member{$q}.{$q}ID{$q} = {$q}Group_Members{$q}.{$q}MemberID{$q}
			LEFT JOIN {$q}Group{$q} ON {$q}Group{$q}.{$q}ID{$q} = {$q}Group_Members{$q}.{$q}GroupID{$q}
			LEFT JOIN {$q}Permission{$q} ON {$q}Permission{$q}.{$q}GroupID{$q} = {$q}Group{$q}.{$q}ID{$q}"
		);
	
	}

	/**
	 * Get all subsites.
	 *
	 * @return DataObjectSet Subsite instances
	 */
	static function getSubsitesForMember($member = null) {
		if(!$member && $member !== FALSE) $member = Member::currentMember();
		if(!$member) return false;

		if(self::hasMainSitePermission($member)) {
			return DataObject::get('Subsite');
		}
		
		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		return DataObject::get(
			'Subsite', 
			"{$q}MemberID{$q} = {$member->ID}", 
			'',
			"LEFT JOIN {$q}Group{$q} ON {$q}Subsite{$q}.{$q}ID{$q} = {$q}SubsiteID{$q}
			LEFT JOIN {$q}Group_Members{$q} ON {$q}Group{$q}.{$q}ID{$q} = {$q}Group_Members{$q}.{$q}GroupID{$q}"
		);
	
	}
	
	static function hasMainSitePermission($member = null, $permissionCodes = array('ADMIN')) {
		if(!is_array($permissionCodes))
			user_error('Permissions must be passed to Subsite::hasMainSitePermission as an array', E_USER_ERROR);

		if(!$member && $member !== FALSE) $member = Member::currentMember();

		if(!$member) return false;

		if(Permission::checkMember($member->ID, "ADMIN")) return true;

		if(Permission::checkMember($member, "SUBSITE_ACCESS_ALL")) return true;

		$SQLa_perm = Convert::raw2sql($permissionCodes);
		$SQL_perms = join("','", $SQLa_perm);
		$memberID = (int)$member->ID;

		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		$groupCount = DB::query("
			SELECT COUNT({$q}Permission{$q}.{$q}ID{$q})
			FROM {$q}Permission{$q}
			INNER JOIN {$q}Group{$q} ON {$q}Group{$q}.{$q}ID{$q} = {$q}Permission{$q}.{$q}GroupID{$q} AND {$q}Group{$q}.{$q}SubsiteID{$q} = 0
			INNER JOIN {$q}Group_Members{$q} USING({$q}GroupID{$q})
			WHERE {$q}Permission{$q}.{$q}Code{$q} IN ('$SQL_perms') AND {$q}MemberID{$q} = {$memberID}
		")->value();
			
		return ($groupCount > 0);
	}

	function createInitialRecords() {

	}

	/**
	 * Duplicate this subsite
	 */
	function duplicate() {
		$newTemplate = parent::duplicate();

		$oldSubsiteID = Session::get('SubsiteID');
		self::changeSubsite($this->ID);

		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		/*
		 * Copy data from this template to the given subsite. Does this using an iterative depth-first search.
		 * This will make sure that the new parents on the new subsite are correct, and there are no funny
		 * issues with having to check whether or not the new parents have been added to the site tree
		 * when a page, etc, is duplicated
		 */
		$stack = array(array(0,0));
		while(count($stack) > 0) {
			list($sourceParentID, $destParentID) = array_pop($stack);

			$children = Versioned::get_by_stage('Page', 'Live', "{$q}ParentID{$q} = $sourceParentID", '');

			if($children) {
				foreach($children as $child) {
					$childClone = $child->duplicateToSubsite($newTemplate, false);
					$childClone->ParentID = $destParentID;
					$childClone->writeToStage('Stage');
					$childClone->publish('Stage', 'Live');
					array_push($stack, array($child->ID, $childClone->ID));
				}
			}
		}

		self::changeSubsite($oldSubsiteID);

		return $newTemplate;
	}


	/**
	 * Return the subsites that the current user can access.
	 * Look for one of the given permission codes on the site.
	 *
	 * Sites will only be included if they have a Title and a Subdomain.
	 * Templates will only be included if they have a Title.
	 *
	 * @param $permCode array|string Either a single permission code or an array of permission codes.
	 */
	function accessible_sites($permCode) {
		$member = Member::currentUser();

		if(is_array($permCode))	$SQL_codes = "'" . implode("', '", Convert::raw2sql($permCode)) . "'";
		else $SQL_codes = "'" . Convert::raw2sql($permCode) . "'";

		if(!$member) return new DataObjectSet();

		$templateClassList = "'" . implode("', '", ClassInfo::subclassesFor("Subsite_Template")) . "'";

		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		return DataObject::get(
			'Subsite',
			"{$q}Group_Members{$q}.{$q}MemberID{$q} = $member->ID
			AND {$q}Permission{$q}.{$q}Code{$q} IN ($SQL_codes, 'ADMIN')
			AND ({$q}Subdomain{$q} IS NOT NULL OR {$q}Subsite{$q}.{$q}ClassName{$q} IN ($templateClassList)) AND {$q}Subsite{$q}.{$q}Title{$q} != ''",
			'',
			"LEFT JOIN {$q}Group{$q} ON ({$q}SubsiteID{$q} = {$q}Subsite{$q}.{$q}ID{$q} OR {$q}SubsiteID{$q} = 0)
			LEFT JOIN {$q}Group_Members{$q} ON {$q}Group_Members{$q}.{$q}GroupID{$q} = {$q}Group{$q}.{$q}ID{$q}
			LEFT JOIN {$q}Permission{$q} ON {$q}Group{$q}.{$q}ID{$q} = {$q}Permission{$q}.{$q}GroupID{$q}"
		);		
	}

	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// CMS ADMINISTRATION HELPERS
	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Return the FieldSet that will build the search form in the CMS
	 */
	function adminSearchFields() {
		return new FieldSet(
			new TextField('Name', 'Sub-site name')
		);
	}

	function providePermissions() {
		return array(
			'SUBSITE_EDIT' => 'Edit Sub-site Details',
			'SUBSITE_ACCESS_ALL' => 'Access all subsites',
			'SUBSITE_ASSETS_EDIT' => 'Edit Sub-site Assets Admin'
		);
	}

	static function get_from_all_subsites($className, $filter = "", $sort = "", $join = "", $limit = "") {
		self::$disable_subsite_filter = true;
		$result = DataObject::get($className, $filter, $sort, $join, $limit);
		self::$disable_subsite_filter = false;
		return $result;
	}

	/**
	 * Disable the sub-site filtering; queries will select from all subsites
	 */
	static function disable_subsite_filter($disabled = true) {
		self::$disable_subsite_filter = $disabled;
	}
}

/**
 * An instance of subsite that can be duplicated to provide a quick way to create new subsites.
 *
 * @package subsites
 */
class Subsite_Template extends Subsite {
	/**
	 * Create an instance of this template, with the given title & subdomain
	 */
	function createInstance($title, $domain) {
		$intranet = Object::create('Subsite');
		$intranet->Title = $title;
		$intranet->TemplateID = $this->ID;
		$intranet->write();
		
		$intranetDomain = Object::create('SubsiteDomain');
		$intranetDomain->SubsiteID = $intranet->ID;
		$intranetDomain->Domain = $domain;
		$intranetDomain->write();

		$oldSubsiteID = Session::get('SubsiteID');
		self::changeSubsite($this->ID);

		if(defined('DB::USE_ANSI_SQL')) 
			$q="\"";
		else $q='`';
		
		/*
		 * Copy site content from this template to the given subsite. Does this using an iterative depth-first search.
		 * This will make sure that the new parents on the new subsite are correct, and there are no funny
		 * issues with having to check whether or not the new parents have been added to the site tree
		 * when a page, etc, is duplicated
		 */
		$stack = array(array(0,0));
		while(count($stack) > 0) {
			list($sourceParentID, $destParentID) = array_pop($stack);

			$children = Versioned::get_by_stage('SiteTree', 'Live', "{$q}ParentID{$q} = $sourceParentID", '');

			if($children) {
				foreach($children as $child) {
					$childClone = $child->duplicateToSubsite($intranet);
					$childClone->ParentID = $destParentID;
					$childClone->writeToStage('Stage');
					$childClone->publish('Stage', 'Live');
					array_push($stack, array($child->ID, $childClone->ID));
				}
			}
		}

		/**
		 * Copy groups from the template to the given subsites.  Each of the groups will be created and left
		 * empty.
		 */
		$groups = DataObject::get("Group", "{$q}SubsiteID{$q} = '$this->ID'");
		if($groups) foreach($groups as $group) {
			$group->duplicateToSubsite($intranet);
		}

		self::changeSubsite($oldSubsiteID);

		return $intranet;
	}
}
?>
