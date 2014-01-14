<?php defined('_JEXEC') or die;

/**
 * File       search_indexer.php
 * Created    1/14/14 10:51 AM
 * Author     Matt Thomas | matt@betweenbrain.com | http://betweenbrain.com
 * Support    https://github.com/betweenbrain/
 * Copyright  Copyright (C) 2014 betweenbrain llc. All Rights Reserved.
 * License    GNU GPL v3 or later
 */

JLoader::register('K2Plugin', JPATH_ADMINISTRATOR . '/components/com_k2/lib/k2plugin.php');

/**
 * Instantiate class for K2 plugin events
 *
 * Class plgK2Search_indexer
 */
class plgK2Search_indexer extends K2Plugin
{

	var $pluginName = 'search_indexer';
	var $pluginNameHumanReadable = 'K2 - Search Indexer';

	/**
	 * Constructor
	 */
	function __construct(&$subject, $results)
	{
		parent::__construct($subject, $results);
		$this->app    = JFactory::getApplication();
		$this->db     = JFactory::getDbo();
		$this->log    = JLog::getInstance();
		$this->plugin = & JPluginHelper::getPlugin('k2', 'search_indexer');
		$this->params = new JParameter($this->plugin->params);
	}

	/**
	 * Update the #__k2_search_soundex table with any new terms used in K2 titles
	 *
	 * @param $row
	 * @param $isNew
	 */
	function onAfterK2Save(&$row, $isNew)
	{

		if ($this->app->isAdmin())
		{
			$categories      = $this->params->get('categories');
			$indexCategories = $this->params->get('indexCategories');
			$indexSoundex    = $this->params->get('indexSoundex');
			$indexTags       = $this->params->get('indexTags');

			if (!is_array($categories))
			{
				$categories = array($categories);
			}

			if (in_array($row->catid, $categories))
			{
				if ($indexSoundex && $this->setSoundexTable())
				{
					$this->setSoundex($row);
				}

				if ($indexCategories)
				{
					$categories = $this->getCategories($row->id);
					$this->setExtraFieldsSearchData($row->id, $categories);
					$this->setpluginsData($row->id, $categories, 'categories');
				}

				if ($indexTags)
				{
					$tags = $this->getTags($row->id);
					$this->setExtraFieldsSearchData($row->id, $tags);
					$this->setpluginsData($row->id, $tags, 'tags');
				}
			}
		}
	}

	/**
	 * Checks for any database errors after running a query
	 *
	 * @param null $backtrace
	 */
	private function checkDbError($backtrace = null)
	{
		if ($error = $this->db->getErrorMsg())
		{
			if ($backtrace)
			{
				$e = new Exception();
				$error .= "\n" . $e->getTraceAsString();
			}

			$this->log->addEntry(array('LEVEL' => '1', 'STATUS' => 'Database Error:', 'COMMENT' => $error));
			JError::raiseWarning(100, $error);
		}
	}

	/**
	 * function to fetch an item's categories
	 *
	 * @param $id
	 *
	 * @return mixed
	 */
	private function getCategories($id)
	{

		$query = 'SELECT catid
			FROM ' . $this->db->nameQuote('#__k2_items') . '
			WHERE Id = ' . $this->db->Quote($id) . '';

		$this->db->setQuery($query);
		$catIds[] = $this->db->loadResult();
		$this->checkDbError();

		$addCatsPlugin = JPluginHelper::isEnabled('k2', 'k2additonalcategories');

		if ($addCatsPlugin)
		{

			$query = 'SELECT catid
				FROM ' . $this->db->nameQuote('#__k2_additional_categories') . '
				WHERE itemID = ' . $this->db->Quote($id);

			$this->db->setQuery($query);
			$addCats = $this->db->loadResultArray();
			$this->checkDbError();

			foreach ($addCats as $addCat)
			{
				$catIds[] = $addCat;
			}
		}

		$query = 'SELECT name
			FROM ' . $this->db->nameQuote('#__k2_categories') . '
			WHERE Id IN (' . implode(',', $catIds) . ')
			AND published = 1';

		$this->db->setQuery($query);
		$categories = $this->db->loadResultArray();
		$this->checkDbError();

		return $categories;
	}

	/**
	 * Gets the plugins data for the specified K2 item
	 *
	 * @param $id
	 *
	 * @return mixed
	 */
	private function getpluginsData($id)
	{
		$query = 'SELECT ' . $this->db->nameQuote('plugins') .
			' FROM ' . $this->db->nameQuote('#__k2_items') .
			' WHERE id = ' . $this->db->Quote($id) . '';

		$this->db->setQuery($query);
		$pluginsData = $this->db->loadResult();
		$this->checkDbError();

		return $pluginsData;
	}

	/**
	 * function to fetch a K2 item's tags
	 *
	 * @param $id
	 *
	 * @return mixed
	 */
	private function getTags($id)
	{
		$query = 'SELECT tag.name
			FROM ' . $this->db->nameQuote('#__k2_tags') . '  as tag
			LEFT JOIN ' . $this->db->nameQuote('#__k2_tags_xref') . '
			AS xref ON xref.tagID = tag.id
			WHERE xref.itemID = ' . $this->db->Quote($id) . '
			AND tag.published = 1';

		$this->db->setQuery($query);
		$tags = $this->db->loadResultArray();
		$this->checkDbError();

		return $tags;
	}

	/**
	 * Adds data to the extra_fields_search column of a K2 item
	 *
	 * @param $id
	 * @param $data
	 */
	private function setExtraFieldsSearchData($id, $data)
	{
		$data  = implode(' ', $data);
		$query = 'UPDATE ' . $this->db->nameQuote('#__k2_items') . '
			SET ' . $this->db->nameQuote('extra_fields_search') . ' = CONCAT(
				' . $this->db->nameQuote('extra_fields_search') . ',' . $this->db->Quote($data) . '
			)
			WHERE id = ' . $this->db->Quote($id) . '';
		$this->db->setQuery($query);
		$this->db->query();
		$this->checkDbError();
	}

	/**
	 * Sets the plugins data for the specified K2 item
	 *
	 * @param $id
	 * @param $data
	 * @param $type
	 */
	private function setpluginsData($id, $data, $type)
	{

		$pluginsData  = $this->getpluginsData($id);
		$pluginsArray = parse_ini_string($pluginsData, false, INI_SCANNER_RAW);
		if ($data)
		{
			$pluginsArray[$type] = implode('|', $data);
		}
		else
		{
			unset($pluginsArray[$type]);
		}
		$pluginData = null;
		foreach ($pluginsArray as $key => $value)
		{
			$pluginData .= "$key=" . $value . "\n";
		}

		$query = 'UPDATE ' . $this->db->nameQuote('#__k2_items') .
			' SET ' . $this->db->nameQuote('plugins') . '=\'' . $pluginData . '\'' .
			' WHERE id = ' . $this->db->Quote($id) . '';

		$this->db->setQuery($query);
		$this->db->query();
		$this->checkDbError();
	}

	/**
	 * Sets the soundex value of each word of the titles belonging to the items in the designated category
	 *
	 * @param $row
	 *
	 * @internal param $ids
	 */
	private function setSoundex($row)
	{

		$titleParts = explode(' ', $row->title);
		foreach ($titleParts as $part)
		{
			// Strip non-alpha characters as we are dealing with language
			$part = preg_replace("/[^\w]/ui", '',
				preg_replace("/[0-9]/", '', $part));
			if ($part)
			{
				$query = 'INSERT INTO ' . $this->db->nameQuote('#__k2_search_soundex') . '
							(' . $this->db->nameQuote('itemId') . ',
							' . $this->db->nameQuote('word') . ',
							' . $this->db->nameQuote('soundex') . ')
							VALUES (' . $this->db->Quote($row->id) . ',
							' . $this->db->Quote($part) . ',
							' . $this->db->Quote(soundex($part)) . ')';
				$this->db->setQuery($query);
				$this->db->query();
			}
		}

	}

	/**
	 * Creates the #__k2_search_soundex table if it doesn't already exist
	 *
	 * @return bool
	 */
	private function setSoundexTable()
	{
		$prefix = $this->app->getCfg('dbprefix');
		$query  = 'CREATE TABLE IF NOT EXISTS `' . $prefix . 'k2_search_soundex` (
						`id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
						`itemId`       INT(11)          NOT NULL,
						`word`         varchar(64)      NOT NULL,
						`soundex`      varchar(5)       NOT NULL,
						PRIMARY KEY (`id`),
						UNIQUE KEY (`word`(64))
					)
						ENGINE =MyISAM
						AUTO_INCREMENT =0
						DEFAULT CHARSET =utf8;';
		$this->db->setQuery($query);
		$this->db->query();

		return true;
	}
}
