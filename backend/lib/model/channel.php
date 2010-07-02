<?php
/*
 * Copyright (c) 2010 by Justin Otherguy <justin@justinotherguy.org>
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License (either version 2 or
 * version 3) as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 * For more information on the GPL, please go to:
 * http://www.gnu.org/copyleft/gpl.html
 */

interface ChannelInterface {
	// data management
	public function addData($data);
	public function getData($from = NULL, $to = NULL, $groupBy = NULL);
	public function reset();

	// some statistical functions
	public function getMin($from = NULL, $to = NULL);
	public function getMax($from = NULL, $to = NULL);
	public function getAverage($from = NULL, $to = NULL);
}

abstract class Channel extends DatabaseObject implements ChannelInterface {
	const table = 'channels';

	public function delete() {
		$this->reset();		// delete all data if database doesn't support foreign keys
		parent::delete();
	}

	/*
	 * deletes all data from database
	 */
	public function reset($from = NULL, $to = NULL) {
		$this->dbh->execute('DELETE FROM data WHERE channel_id = ' . (int) $this->id) . $this->buildTimeFilter($from, $to);
	}

	/*
	 * add a new data to the database
	 */
	public function addData($data) {
		$sql = 'INSERT INTO data (channel_id, timestamp, value) VALUES(' . $this->dbh->escape($this) . ', ' . $this->dbh->escape($data['timestamp']) . ', ' . $this->dbh->escape($data['value']) . ')';
		$this->dbh->execute($sql);
	}

	/*
	 * This function retrieve data from the database. If desired it groups it into packages ($groupBy parameter)
	 *
	 * @return array() Array with timestamps => value (sorted by timestamp from newest to oldest)
	 * @param $groupBy determines how readings are grouped. Possible values are: year, month, day, hour, minute or an integer for the desired size of the returned array
	 */
	public function getData($from = NULL, $to = NULL, $groupBy = NULL) {
		$ts = 'FROM_UNIXTIME(timestamp/1000)';	// just for saving space
		switch ($groupBy) {
			case 'year':
				$sqlGroupBy = 'YEAR(' . $ts . ')';
				break;

			case 'month':
				$sqlGroupBy = 'YEAR(' . $ts . '), MONTH(' . $ts . ')';
				break;

			case 'week':
				$sqlGroupBy = 'YEAR(' . $ts . '), WEEKOFYEAR(' . $ts . ')';
				break;

			case 'day':
				$sqlGroupBy = 'YEAR(' . $ts . '), DAYOFYEAR(' . $ts . ')';
				break;

			case 'hour':
				$sqlGroupBy = 'YEAR(' . $ts . '), DAYOFYEAR(' . $ts . '), HOUR(' . $ts . ')';
				break;

			case 'minute':
				$sqlGroupBy = 'YEAR(' . $ts . '), DAYOFYEAR(' . $ts . '), HOUR(' . $ts . '), MINUTE(' . $ts . ')';
				break;

			default:
				if (is_numeric($groupBy)) {
					$groupBy = (int) $groupBy;
				}
				$sqlGroupBy = false;
		}

		$sql = 'SELECT';
		$sql .= ($sqlGroupBy === false) ? ' timestamp, value' : ' MAX(timestamp) AS timestamp, SUM(value) AS value, COUNT(timestamp) AS count';
		$sql .= ' FROM data WHERE channel_id = ' . (int) $this->id . $this->buildFilterTime($from, $to);

		if ($sqlGroupBy !== false) {
			$sql .= ' GROUP BY ' . $sqlGroupBy;
		}
			
		$sql .= ' ORDER BY timestamp DESC';
		$result = $this->dbh->query($sql);
		$totalCount = $result->count();

		if (is_int($groupBy) && $groupBy < $totalCount) {	// return $groupBy values
			$packageSize = floor($totalCount / $groupBy);
			$packageCount = $groupBy;
		}
		else {												// return all values or grouped by year, month, week...
			$packageSize = 1;
			$packageCount = $totalCount;
		}

		$packages = array();
		$reading = $result->rewind();
		for ($i = 1; $i <= $packageCount; $i++) {
			$package = array('timestamp' => $reading['timestamp'],	// last timestamp in package
								'value' => (float) $reading['value'],		// sum of values
								'count' => ($sqlGroupBy === false) ? 1 : $reading['count']);						// total count of values or pulses in the package

			while ($package['count'] < $packageSize) {
				$reading = $result->next();

				$package['value'] += $reading['value'];
				$package['count']++;
			}

			$packages[] = $package;
			$reading = $result->next();
		}

		return array_reverse($packages);	// start with oldest ts and ends with newest ts (reverse array order due to descending order in sql statement)
	}

	/*
	 * simple self::getByFilter() wrapper
	 */
	static public function getByType($type) {
		return self::getByFilter(array('type' => $type));
	}

	/*
	 * create new channel instance by given database query result
	 */
	final static protected function factory($object) {
		if (!class_exists($object['type']) || !is_subclass_of($object['type'], 'Channel')) {
			throw new InvalidArgumentException('\'' . $object['type'] . '\' is not a valid channel type');
		}
		
		// code duplication from DatabaseObject::factory()
		if (!isset(self::$instances[self::table])) {
			self::$instances[self::table] = array();
		}
		
		if (!isset(self::$instances[self::table][$object['id']])) {
			self::$instances[self::table][$object['id']] = new $object['type']($object);	// create singleton instance of database object
		}
		
		return self::$instances[self::table][$object['id']];	// return singleton instance of database object
	}

	/*
	 * build simple timeframe filter
	 */
	static protected function buildFilterTime($from = NULL, $to = NULL) {
		$sql = '';

		if (is_int($to) && $to <= time() * 1000) {
			$sql .= ' && timestamp < ' . $to;
		}

		if (is_int($from) && $from > 0) {
			$sql .= ' && timestamp > ' . $from;
		}

		return $sql;
	}
	
	/*
	 * data filtering
	 */
	static public function getByFilter($filters = array(), $conjunction = true) {
		$joins = array();
		foreach ($filters as $column => $value) {
			if (!key_exists('groups', $joins) && preg_match('/^group\.([a-z_]+)$/', $column)) {
				$joins['channels_in_groups'] = array('type' => 'left', 'table' => 'channels_in_groups', 'condition' => 'channels_in_groups.channel_id = ' . self::table . '.id');
				$joins['groups'] = array('type' => 'left', 'table' => 'groups AS group', 'condition' => 'groups.id = channels_in_groups.group_id');
			}
		}
		
		$result = Database::getConnection()->select(self::table, array(self::table . '.*'), $filters, $conjunction, $joins);
		
		$instances = array();
		foreach ($result as $object) {
			$instances[$object['id']] = static::factory($object);
		}
	
		return $instances;
	}
}