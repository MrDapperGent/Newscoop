<?php
/**
 * Includes
 */
require_once($GLOBALS['g_campsiteDir'].'/db_connect.php');
require_once($GLOBALS['g_campsiteDir'].'/classes/DatabaseObject.php');
require_once($GLOBALS['g_campsiteDir'].'/classes/DbObjectArray.php');
require_once($GLOBALS['g_campsiteDir'].'/classes/Log.php');

/**
 * @package Campsite
 */
class Topic extends DatabaseObject {
	var $m_keyColumnNames = array('id');

	var $m_keyIsAutoIncrement = false;

	var $m_dbTableName = 'Topics';

	var $m_columnNames = array('id', 'node_left', 'node_right');

	var $m_hasSubtopics = null;

	var $m_names = array();


	/**
	 * A topic is like a category for a piece of data.
	 *
	 * @param int $p_id
	 */
	public function Topic($p_idOrName = null)
	{
		parent::DatabaseObject($this->m_columnNames);

		if (preg_match('/^[\d]+$/', $p_idOrName) > 0) {
            $this->m_data['id'] = $p_idOrName;
            $this->fetch();
		} elseif (is_string($p_idOrName) && !empty($p_idOrName)) {
		    $topic = Topic::GetByFullName($p_idOrName);
		    if (!is_null($topic)) {
		        $this->duplicateObject($topic);
		    }
		}
	} // constructor


	/**
	 * Fetch the topic and all its translations.
	 *
	 * The values array may have the following keys:
	 * - id - topic identifier; if not supplied generated automatically
	 * - node_left
	 * - node_right
	 * - names - array of topic translations of the form: language_id => name
	 *
	 * @return void
	 */
	public function fetch($p_columns = null)
	{
		global $g_ado_db;

		if (!is_null($p_columns)) {
			if (!isset($p_columns['names'])) {
				return false;
			}
			if ($this->readFromCache($p_columns) !== false) {
                return true;
            }
			foreach ($p_columns as $columnName => $value) {
				if (in_array($columnName, $this->m_columnNames)) {
					$this->m_data[$columnName]  = $value;
				}
			}
			$this->m_names = $p_columns['names'];
			$this->m_exists = true;
		} else {
            if ($this->readFromCache() !== false) {
                return true;
            }
            parent::fetch();
            if ($this->exists()) {
				$this->m_names = TopicName::GetTopicNames($this->getTopicId());
            }
		}

		if ($this->m_exists) {
		    // Write the object to cache
		    $this->writeCache();
		}

        return $this->m_exists;
	} // fn fetch


	/**
	 * Create a new topic.
	 *
	 * The values array may have the following keys:
	 * - parent_id - parent topic identifier
	 * - id - topic identifier; if not supplied generated automatically
	 * - node_left
	 * - node_right
	 * - names - array of topic translations of the form: language_id => name
	 *
	 * @param array $p_values
	 * @return boolean
	 */
	public function create($p_values = null)
	{
		global $g_ado_db;

		if (!isset($p_values['names'])) {
			return false;
		}

		$g_ado_db->Execute("LOCK TABLE Topics WRITE, TopicNames WRITE");

		$this->m_names = $p_values['names'];

		if (!empty($p_values['parent_id'])) {
			$parent = new Topic($p_values['parent_id']);
			if (!$parent->exists()) {
				$g_ado_db->Execute("UNLOCK TABLES");
				return false;
			}
			$parentLeft = (int)$parent->getLeft();
		} else {
			$parentLeft = 0;
		}

		$g_ado_db->Execute("UPDATE Topics SET node_left = node_left + 2 WHERE node_left > $parentLeft");
		$g_ado_db->Execute("UPDATE Topics SET node_right = node_right + 2 WHERE node_right > $parentLeft");

		$this->m_data['node_left'] = $parentLeft + 1;
		$this->m_data['node_right'] = $parentLeft + 2;

		// create node
		if ($success = parent::create($p_values)) {
			// create topic names
			foreach ($this->m_names as $languageId=>$name) {
				$topicName = new TopicName($this->getTopicId(), $languageId);
				$topicName->create(array('name'=>$name));
			}
		}

		$g_ado_db->Execute("UNLOCK TABLES");

		if ($success) {
			$this->m_exists = true;
			if (function_exists("camp_load_translation_strings")) {
				camp_load_translation_strings("api");
			}
			$logtext = getGS('Topic "$1" ($2) added', $this->m_data['Name'], $this->m_data['Id']);
			Log::Message($logtext, null, 141);
		}
		CampCache::singleton()->clear('user');
		return $success;
	} // fn create


	/**
	 * Returns the node left order
	 * @param bool $p_forceFetchFromDatabase
	 */
	public function getLeft($p_forceFetchFromDatabase = false)
	{
		return $this->getProperty('node_left', $p_forceFetchFromDatabase);
	} // fn getLeft


	/**
	 * Returns the node right order
	 * @param bool $p_forceFetchFromDatabase
	 */
	public function getRight($p_forceFetchFromDatabase = false)
	{
		return $this->getProperty('node_right', $p_forceFetchFromDatabase);
	} // fn getRight


	/**
	 * Set the given column name to the given value.
	 * The object's internal variable will also be updated.
	 * If the value hasn't changed, the database will not be updated.
	 *
	 * Note: Returns false when setting the fields node_left and node_right.
	 * We don't want to allow direct update of these fields.
	 *
	 * Note: You cannot set $p_commit to FALSE and $p_isSql to TRUE
	 * at the same time.
	 *
	 * @param string $p_dbColumnName
	 *		The name of the column that is to be updated.
	 *
	 * @param string $p_value
	 *		The value to set.
	 *
	 * @param boolean $p_commit
	 *		If set to true, the value will be written to the database immediately.
	 *		If set to false, the value will not be written to the database.
	 *		Default is true.
	 *
	 * @param boolean $p_isSql
	 *		Set this to TRUE if p_value consists of SQL commands.
	 *		There is no way to know what the result of the command is,
	 *		so we will need to refetch the value from the database in
	 *		order to update the internal variable's value.
	 *
	 * @return boolean
	 *		TRUE on success, FALSE on error.
	 */
	public function setProperty($p_dbColumnName, $p_value, $p_commit = true, $p_isSql = false)
	{
		if ($p_dbColumnName == 'node_left' || $p_dbColumnName == 'node_right') {
			return false;
		}
		return parent::setProperty($p_dbColumnName, $p_value, $p_commit, $p_isSql);
	} // fn setProperty


	/**
	 * Delete the topic.
	 * @return boolean
	 */
	public function delete($p_languageId = null)
	{
		global $g_ado_db;

		$g_ado_db->Execute("LOCK TABLE Topics WRITE, TopicNames WRITE, TopicFields READ, ArticleTypeMetadata WRITE");

		if ($p_languageId > 0 && $this->getNumTranslations() > 1) {
			$topicName = new TopicName($this->getTopicId(), $p_languageId);
			$deleted = $topicName->delete();
		} else {
			// Delete the article type field metadata
			if ($deleted) {
				$sql = "SELECT * FROM TopicFields WHERE RootTopicId IN "
				."(SELECT DISTINCT Id FROM Topics WHERE node_left >= ". $this->m_data['node_left']
				." AND node_right < " . $this->m_data['node_right'] . ")";
				$row = $g_ado_db->GetAll($sql);
				foreach ($rows as $row) {
					$delATF = new ArticleTypeField($row['ArticleType'], $row['FieldName']);
					$delATF->delete();
				}
			}

			// Delete topic names
			TopicName::DeleteTopicNames($this->getTopicId());

			// Delete children and itself
			$sql = "DELETE FROM Topics WHERE node_left >= ".$this->m_data['node_left']
			. ' AND node_right < '.$this->m_data['node_right'];
			$deleted = $g_ado_db->Execute($sql);

			if ($deleted) {
				$myWidth = $this->m_data['node_right'] - $this->m_data['node_left'] + 1;
				$sql = "UPDATE Topics SET node_left = node_left - $myWidth WHERE node_left > " . $this->m_data['node_left'];
				$g_ado_db->Execute($sql);
				$sql = "UPDATE Topics SET node_right = node_right - $myWidth WHERE node_right > " . $this->m_data['node_right'];
				$g_ado_db->Execute($sql);
			}

			$this->m_data = array();
			$this->m_exists = false;
		}

		$g_ado_db->Execute("UNLOCK TABLES");

		if ($deleted) {
			if (function_exists("camp_load_translation_strings")) {
				camp_load_translation_strings("api");
			}
			if (is_null($p_languageId)) {
				$name = implode(",", $this->m_names);
			} else {
				$name = $this->m_names[$p_languageId];
			}
			$logtext = getGS('Topic "$1" ($2) deleted', $name, $this->m_data['Id']);
			Log::Message($logtext, null, 142);
		}
		CampCache::singleton()->clear('user');
		return $deleted;
	} // fn delete


	/**
	 * @return string
	 */
	public function getName($p_languageId)
	{
		if (is_numeric($p_languageId) && isset($this->m_names[$p_languageId])) {
			return $this->m_names[$p_languageId]->getName();
		} else {
			return "";
		}
	} // fn getName


	/**
	 * Set the topic name for the given language.  A new entry in
	 * the database will be created if the language does not exist.
	 *
	 * @param int $p_languageId
	 * @param string $p_value
	 *
	 * @return boolean
	 */
	public function setName($p_languageId, $p_value)
	{
		global $g_ado_db;
		if (!is_string($p_value) || !is_numeric($p_languageId)) {
			return false;
		}

		if (isset($this->m_names[$p_languageId])) {
			// Update the name.
			$changed = $this->m_names[$p_languageId]->setName($p_value);
		} else {
			$topicName = new TopicName($this->getTopicId(), $p_languageId);
			$changed = $topicName->create(array('name'=>$p_value));
		}
		if ($changed) {
			$this->m_names[$p_languageId] = $p_value;
			if (function_exists("camp_load_translation_strings")) {
				camp_load_translation_strings("api");
			}
			$logtext = getGS('Topic $1: ("$2" -> "$3") updated', $this->m_data['Id'], $oldValue, $this->m_names[$p_languageId]);
			Log::Message($logtext, null, 143);
		}
		return $changed;
	} // fn setName


	/**
	 * @return int
	 */
	public function getTopicId()
	{
		return $this->m_data['id'];
	} // fn getTopicId


	/**
	 * Get all translations of the topic in an array indexed by
	 * the language ID.
	 *
	 * @return array
	 */
	public function getTranslations()
	{
	    return $this->m_names;
	} // fn getTranslations


	/**
	 * Return the number of translations of this topic.
	 *
	 * @return int
	 */
	public function getNumTranslations()
	{
		return count($this->m_names);
	} // fn getNumTranslations


	/**
	 * @return int
	 */
	public function getParentId()
	{
		global $g_ado_db;

		$sql = 'SELECT DISTINCT id FROM Topics WHERE node_left < ' . $this->getLeft()
		. ' AND node_right > ' . $this->getRight() . ' ORDER BY Id DESC LIMIT 0, 1';
		$parentId = $g_ado_db->GetOne($sql);
		return $parentId;
	} // fn getParentId


	/**
	 * Return an array of Topics starting from the root down
	 * to and including the current topic.
	 *
	 * @return array
	 */
	public function getPath()
	{
		global $g_ado_db;

		$stack = array();
		$sql = 'SELECT * FROM Topics WHERE node_left <= ' . $this->getLeft()
		. ' AND node_right >= ' . $this->getRight() . ' ORDER BY node_left ASC';
		$rows = $g_ado_db->GetAll($sql);
		foreach ($rows as $row) {
			$stack[] = new Topic($row['id']);
		}
		return $stack;
	} // fn getPath


	/**
	 * Returns true if it was a root topic
	 * @return boolean
	 */
    public function isRoot()
    {
    	global $g_ado_db;

    	$sql = 'SELECT COUNT(*) FROM Topics WHERE node_left < ' . $this->getLeft()
    	. ' AND node_right > ' . $this->getRight();
    	$parentsCount = $g_ado_db->GetOne($sql);
    	return $parentsCount == 0;
    } // fn isRoot


	/**
	 * Return true if this topic has subtopics.
	 *
	 * @return boolean
	 */
	public function hasSubtopics()
	{
		return ($this->getRight() - $this->getLeft()) > 1;
	} // fn hasSubtopics


	/**
	 * Returns a topic object identified by the full name in the
	 * format topic_name:language_code
	 *
	 * @param string $p_fullName
	 * @return Topic object
	 */
	public static function GetByFullName($p_fullName)
	{
	    $components = preg_split('/:/', trim($p_fullName));
	    if (count($components) < 2) {
	        return null;
	    }
	    $name = $components[0];
	    $languageCode = $components[1];

	    $languages = Language::GetLanguages(null, $languageCode, null, array(), array(), false);
	    if (count($languages) < 1) {
	        return null;
	    }
        $languageObject = $languages[0];

        $topics = Topic::GetTopics(null, $languageObject->getLanguageId(), $name);
	    if (count($topics) < 1) {
	        return null;
	    }

	    return $topics[0];
	} // fn GetByFullName


	/**
	 * Search the Topics table.
	 *
	 * @param int $p_id
	 * @param int $p_languageId
	 * @param string $p_name
	 * @param int $p_parentId
	 * @param array $p_sqlOptions
	 * @return array
	 */
	public static function GetTopics($p_id = null, $p_languageId = null, $p_name = null,
					                 $p_parentId = null, $p_sqlOptions = null,
					                 $p_order = null, $p_countOnly = false)
	{
        global $g_ado_db;
		if (!$p_skipCache && CampCache::IsEnabled()) {
            $paramsArray['id'] = (is_null($p_id)) ? '' : $p_id;
            $paramsArray['language_id'] = (is_null($p_languageId)) ? '' : $p_languageId;
            $paramsArray['name'] = (is_null($p_name)) ? '' : $p_name;
            $paramsArray['parent_id'] = (is_null($p_parentId)) ? '' : $p_parentId;
            $paramsArray['sql_options'] = $p_sqlOptions;
            $paramsArray['order'] = $p_order;
            $paramsArray['count_only'] = (int)$p_countOnly;
            $cacheListObj = new CampCacheList($paramsArray, __METHOD__);
            $topics = $cacheListObj->fetchFromCache();
            if ($topics !== false && is_array($topics)) {
                return $p_countOnly ? $topics['count'] : $topics;
            }
        }


        $topicObj = new Topic();
		$topicTable = '`' . $topicObj->m_dbTableName . '`';
		$topicNameObj = new TopicName();
		$topicNameTable = '`' . $topicNameObj->m_dbTableName . '`';

        $in_columns[] = 't.id';
        $in_tables[] = "$topicTable AS t";
		$in_sqlOptions['GROUP BY'] = array('t.id');

        $constraints = array();
		$in_constraints = array();
		if (!is_null($p_id) && is_numeric($p_id)) {
			$in_constraints[] = "t.id = '$p_id'";
		}
		if (!is_null($p_languageId) && is_numeric($p_languageId)) {
			$constraints[] = "tn.fk_language_id = '$p_languageId'";
		}
		if (!is_null($p_name)) {
			$constraints[] = "tn.name = '". $g_ado_db->escape($p_name) . "'";
		}
		if (!is_null($p_parentId)) {
			$in_tables[] = "$topicTable AS parent";
			$in_constraints[] = 't.node_left BETWEEN parent.node_left AND parent.node_right';
			$in_tables[] = "$topicTable AS sub_parent";
			$in_constraints[] = 't.node_left BETWEEN sub_parent.node_left AND sub_parent.node_right';
			$in_tables[] = "(SELECT child.id, (COUNT(d_parent.id) - 1) AS depth\n"
					  . "  FROM $topicTable AS child, $topicTable AS d_parent\n"
					  . "  WHERE child.node_left BETWEEN d_parent.node_left AND d_parent.node_right\n"
					  . "    AND child.id = '$p_parentId'\n"
					  . "  GROUP BY child.id\n"
					  . "  ORDER BY child.node_left) AS sub_tree";
			$in_constraints[] = 'sub_parent.id = sub_tree.id';
			$in_sqlOptions['HAVING'] = 'depth = 1';
			$in_columns[] = '(COUNT(parent.id) - (sub_tree.depth + 1)) AS depth';
		}
		$queryStr = "SELECT " . implode(', ', $in_columns) . " \nFROM " . implode(",\n  ", $in_tables);
        if (count($in_constraints) > 0) {
        	$queryStr .= " \nWHERE ".implode("\n  AND ", $in_constraints);
        }
        $queryStr = DatabaseObject::ProcessOptions($queryStr, $in_sqlOptions);
        $constraints[] = "t.id = in_query.id";

		if (!is_array($p_order) || count($p_order) == 0) {
			$p_order = array(array('field'=>'default', 'dir'=>'asc'));
		}
		foreach ($p_order as $orderCond) {
			switch (strtolower($orderCond['field'])) {
				case 'default':
					$order['t.node_left'] = $orderCond['dir'];
					break;
				case 'byname':
					$order['tn.name'] = $orderCond['dir'];
					break;
				case 'bynumber':
					$order['t.id'] = $orderCond['dir'];
					break;
			}
		}
		$p_sqlOptions['ORDER BY'] = $order;

        $queryStr = "SELECT DISTINCT t.id \n"
        ."FROM $topicTable AS t LEFT JOIN $topicNameTable AS tn ON t.id = tn.fk_topic_id, \n"
        ."  ($queryStr) AS in_query\n"
        ."WHERE " . implode("\n  AND ", $constraints);
        $queryStr = DatabaseObject::ProcessOptions($queryStr, $p_sqlOptions);
        if ($p_countOnly) {
        	$queryStr = "SELECT COUNT(*) FROM ($queryStr) AS topics";
        	$topics['count'] = $g_ado_db->GetOne($queryStr);
        } else {
        	$topics = array();
        	$rows = $g_ado_db->GetAll($queryStr);
        	foreach ($rows as $row) {
        		$topics[] = new Topic($row['id']);
        	}
        }

        if (!$p_skipCache && CampCache::IsEnabled()) {
            $cacheListObj->storeInCache($topics);
        }

        return $topics;
	} // fn GetTopics


	/**
	 * Returns the subtopics from the next level (not all levels below) in an array
	 * of topic identifiers.
	 * @param array $p_returnIds
	 */
	public function getSubtopics($p_returnIds = false)
	{
        global $g_ado_db;

		$sql = "SELECT DISTINCT Id FROM Topics WHERE ParentId = " . (int)$this->m_data['Id'];
		$rows = $g_ado_db->GetAll($sql);
		$topics = array();
		foreach ($rows as $row) {
			$topics[] = $p_returnIds ? $row['Id'] : new Topic($row['Id']);
		}
		return $topics;
	} // getSubtopics


	/**
	 * Get all the topics in an array, where each element contains the entire
	 * path for each topic.  Each topic will be indexed by its ID.
	 * For example, if we have the following topic structure (IDs are
	 * in brackets):
	 *
	 * sports (1)
	 *  - baseball (2)
	 *  - soccer (3)
	 *    - player stats (4)
	 *    - matches (5)
	 * politics (6)
	 *  - world (7)
	 *  - local (8)
	 *
	 *  ...then the returned array would look like:
	 *  array(array(1 => "sports"),
	 *        array(1 => "sports", 2 => "baseball"),
	 *        array(1 => "sports", 3 => "soccer"),
	 *        array(1 => "sports", 3 => "soccer", 4 => "player stats"),
	 *        array(1 => "sports", 3 => "soccer", 5 => "matches"),
	 *        array(6 => "politics"),
	 *        array(6 => "politics", 7 => "world"),
	 *        array(6 => "politics", 8 => "local")
	 *  );
	 *
	 * @param int $p_startingTopicId
	 * @return array
	 */
	public static function GetTree($p_startingTopicId = 0)
	{
		$tree = array();
		$path = array();
		Topic::__TraverseTree($tree, $path, $p_startingTopicId);
		return $tree;
	} // fn GetTree


    /**
     * Update order for all items in tree.
     *
     * @param array $order
     *      $parent =>  array(
     *          $order => $topicId
     *      );
     *  @return bool
     */
    public static function UpdateOrder(array $p_order)
    {
		global $g_ado_db;

        $g_ado_db->StartTrans();
        foreach ($p_order as $parentId => $order) {
            foreach ($order as $topicOrder => $topicId) {
                list(, $topicId) = explode('_', $topicId);
                $queryStr = 'UPDATE Topics
                    SET TopicOrder = ' . ((int) $topicOrder) . '
                    WHERE Id = ' . ((int) $topicId);
                $g_ado_db->Execute($queryStr);
            }
        }
        $g_ado_db->CompleteTrans();

        return TRUE;
    } // fn UpdateOrder

} // class Topics

?>
