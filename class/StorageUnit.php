<?php 


class StorageUnit {

	public $box_id;  // string for box unit  (eg 1A or C11)
	public $drawer_indicator;  // drawer or shelf letter or number
	public $unit_indicator;  // sub category inside the drawer
	public $drawer_size;  // number of division for drawer or shelf [rows, columns]
	public $start;  // subdivision start of drawer or shelf [rows, columns]
	public $relative_start;  // the position relative to a top down table (like HTML tables)
	public $span;  // subdivision end of drawer or shelf [rows, columns]
	public $trans_id;  // trans_id of stored item
	public $item_change_time;  // date of when item was placed/stored in unit
	public $staff;  // staff member to insert/remove item
	public $type;  // what the section label will be (eg small, large, glass, etc)

	public function __construct($box_id) {
		global $mysqli;
		global $sv;

		// ---- get and sort data to populate fields ----
		$this->box_id = $box_id;
		if($sv['strg_drwr_indicator'] == "numer") {
			$this->drawer_indicator = preg_replace('/[^0-9]/', '', $box_id);
			$this->unit_indicator = preg_replace( '/[^a-zA-Z]/', '', $box_id);
		}
		else {
			$this->drawer_indicator = preg_replace( '/[^a-zA-Z]/', '', $box_id);
			$this->unit_indicator = preg_replace('/[^0-9]/', '', $box_id);
		}

		if($results = $mysqli->query("SELECT *
										FROM `storage_box`
										WHERE `drawer` = '$this->drawer_indicator'
										AND `unit` = '$this->unit_indicator';
		")) {
			if($results->num_rows == 1) {
				$unit = $results->fetch_assoc();
				$this->drawer_size = array_map('intval', explode("-", $unit['drawer_size']));
				$this->start = array_map('intval', explode("-", $unit['start']));
				$this->span = array_map('intval', explode("-", $unit['span']));
				$this->trans_id = $unit['trans_id'];
				$this->item_change_time = $unit['item_change_time'];
				$this->staff = $unit['staff'];
				$this->type = $unit['type'];
			}
		}
		else {
			// delete self cause it done messed up
		}

	}


	public function cell_is_start($position) {
		return $position === $this->start;
	}

	
	public static function cell_is_start_of_unit($drawer, $position) {
		foreach($drawer as $unit) {
			if($unit->cell_is_start($position)) return $unit;
		}
	}


	public static function copy_drawer($new_drawer_name, $old_drawer_name) {
		global $mysqli;

		$new_drawer_name = self::regex_drawer_indicator($new_drawer_name);
		$old_drawer_name = self::regex_drawer_indicator($old_drawer_name);

		if(!$new_drawer_name || !$old_drawer_name) return false;

		$statement = $mysqli->prepare("INSERT INTO `storage_box` (`drawer`, `unit`, `drawer_size`, `start`, `span`, `type`)
    						SELECT ?, `unit`, `drawer_size`, `start`, `span`, `type` 
    						FROM `storage_box` 
    						WHERE `drawer` = ?;");

		$statement->bind_param("ss", $new_drawer_name, $old_drawer_name);
		if($statement->execute()) {
			return true;
		}
		return false;
	}


	//TODO: clean up
	public function create_box_selection($drawer) {
		$selector = "<table style='max-width:".strval(50*$this->drawer_size[1])."px;'> ";
		for($x = 1; $x <= $this->drawer_size[0]; $x++) {
			$row = " <tr style='height:50px;'> ";
			for($y = 1; $y <= $this->drawer_size[1]; $y++) {
				if($unit = $this->cell_is_start_of_unit($drawer, array($x, $y))) {
					$row .= " 
					<td id='$unit->unit_indicator' class='unit unselectable' colspan='".($unit->span[1]+1)."' rowspan='".($unit->span[0]+1).
					"' style='width:".strval(50*($unit->span[1]+1))."px;' onclick='delete_unit(this)' 
					onmouseover='track(this);' onmouseout='untrack(this);' align='center'>$unit->unit_indicator ($unit->type)</td> ";
				}
				elseif($this->no_unit_is_using_cell($drawer, array($x, $y))) {
					$row .= " 
					<td id='$x-$y' class='free selectable' align='center' onclick='bound_partition(this, \"edit_partition_input\", \"free\");' 
					onmouseover='track(this);' onmouseout='untrack(this)';>$x-$y</td> ";
				}
			}
			$selector .= $row."</tr>";
		}
		$selector .= "
			<tr> 
				<td colspan='".$this->drawer_size[1]."' align=CENTER style='font-size:1.5em'>
					<span style='text-decoration:overline'><strong>Drawer Front</strong></span>
				</td> 
			<tr> 
		</table>";
		return $selector;
	}


	public static function create_new_partition($drawer, $unit, $start, $span, $type, $size = false) {
		if(!$size) $size = self::get_drawer_size($drawer);

		$drawer = self::regex_drawer_indicator($drawer);
		$unit = self::regex_unit_indicator($unit);
		$size = self::regex_drawer_size($size);
		$start = self::regex_drawer_start($start);
		$span = self::regex_drawer_span($span);
		$type = self::regex_drawer_type($type);

		global $mysqli;
		$statement = $mysqli->prepare("INSERT INTO `storage_box` (`drawer`, `unit`, `drawer_size`, `start`, `span`, `type`) VALUES 
						(?, ?, ?, ?, ?, ?);");
		$statement->bind_param("ssssss", $drawer, $unit, $size, $start, $span, $type);
		if($statement->execute()) return true;
		return false;
	}


	public static function delete_drawer($drawer) {
		$drawer = self::regex_drawer_indicator($drawer);

		if(self::drawer_currently_holds_any_item($drawer)) return "Can not delete drawer because drawer is not empty";

		global $mysqli;
		$statement = $mysqli->prepare("DELETE FROM `storage_box`
											WHERE `drawer` = ?;");
		$statement->bind_param("s", $drawer);
		if($statement->execute()) return null;
		return "Failed to delete drawer";
	}


	public static function delete_unit($drawer, $unit) {
		$drawer = self::regex_drawer_indicator($drawer);
		$unit = self::regex_unit_indicator($unit);

		if(self::unit_currently_holds_an_item($drawer, $unit)) return "Cannot delete unit because unit is not empty";

		global $mysqli;
		$statement = $mysqli->prepare("DELETE FROM `storage_box`
											WHERE `drawer` = ?
											AND `unit` = ?;");
		$statement->bind_param("ss", $drawer, $unit);
		if($statement->execute()) return null;
		return "Failed to delete unit";
	}


	// get the different cells for a unit
	public function determine_contained_subdivision() {
		for($x = $this->start[0]; $x <= $this->start[0] + $this->span[0]; $x++) {
			for($y = $this->start[1]; $y <= $this->start[1] + $this->span[1]; $y++) {
				$this->contained_subdivisions[] = array($x, $y);
			}
		}
	}


	public static function drawer_exists($drawer) {
		$drawer = self::regex_drawer_indicator($drawer);

		global $mysqli;
		if($results = $mysqli->query("SELECT *
										FROM `storage_box`
										WHERE `drawer` = '$drawer';"
		)) {
			return $results->num_rows > 0;
		}
	}


	public static function drawer_currently_holds_any_item($drawer) {
		$drawer = self::regex_drawer_indicator($drawer);

		global $mysqli;
		if($results = $mysqli->query("SELECT *
										FROM `storage_box`
										WHERE `trans_id` IS NOT NULL
										AND `drawer` = '$drawer';"
		)) {
			if($results->num_rows == 0) return false;
		}
		return true;  // could not query or size not 0, so assume drawer holds item
	}


	public static function get_drawer_containing_item($trans_id) {

	}


	public static function get_drawer_size($drawer) {
		global $mysqli;

		if($results = $mysqli->query("SELECT `drawer_size` 
										FROM `storage_box` 
										WHERE `drawer` = '$drawer' 
										GROUP BY `drawer`;
		")) {
			return $results->fetch_assoc()['drawer_size'];
		}
		return NULL;
	}


	public static function get_drawer_units_labels($drawer) {
		$drawer = self::regex_drawer_indicator($drawer);

		global $mysqli;
		if($results = $mysqli->query("SELECT `unit`
										FROM `storage_box`
										WHERE `drawer` = '$drawer';"
		)) {
			$unit_indicators_for_drawer = array();
			while($row = $results->fetch_assoc())
				$unit_indicators_for_drawer[] = $row['unit'];
			return $unit_indicators_for_drawer;
		}
	}


	public static function get_numeric_drawer_size($drawer) {
		global $mysqli;

		if($results = $mysqli->query("SELECT `drawer_size` 
										FROM `storage_box` 
										WHERE `drawer` = '$drawer' 
										GROUP BY `drawer`;
		")) {
			return array_map('intval', explode("-", $results->fetch_assoc()['drawer_size']));
		}
		return NULL;
	}


	// return array of objects for all units with the desired row id
	public static function get_units_for_drawer($drawer) {
		$drawer = self::regex_unit_indicator($drawer);

		global $mysqli;

		$row_units = array();
		if($results = $mysqli->query("SELECT `drawer`, `unit`
										FROM `storage_box`
										WHERE `drawer` = '$drawer';"
		)) {
			while($storage_unit = $results->fetch_assoc()) {
				$row_units[] = new StorageUnit($storage_unit['drawer'].$storage_unit['unit']);
			}
		}
		return $row_units;
	}


	// find the unit currently holding the sought object; return object of unit
	public function get_unit_for_trans_id($trans_id) {
		global $mysqli;

		return $unit;
	}


	public static function get_unique_drawers($type = false) {
		global $mysqli;

		$drawer_designations = array();
		if($type) {
			$results = $mysqli->query("SELECT DISTINCT `drawer`
											FROM `storage_box`
											WHERE `type` = '$type';");
		}
		else {
			$results = $mysqli->query("SELECT DISTINCT `drawer`
											FROM `storage_box`");
		}
		if($results) {
			while($drawer = $results->fetch_assoc()) {
				$drawer_designations[] = $drawer['drawer'];
			}
			return $drawer_designations;
		}
		return array("Could not get drawer information");
	} 


	public static function no_unit_is_using_cell($drawer, $position) {
		foreach($drawer as $unit) {
			if($unit->position_is_a_part_of_unit($position)) return false;
		}
		return true;
	}


	public static function partition_drawer($drawer, $unit, $start, $span, $type) {

		$statement = $mysqli->prepare("INSERT INTO `storage_box` (`drawer`, `unit`, `drawer_size`, `start`, `span`, `type`) VALUES 
						(?, ?, ?, ?, ?, ?);");
		$statement->bind_param("ssssss", $name, $drawer, $rows_and_columns, $start, $span, $type);
		if($statement->execute()) {
			return true;
		}
		return false;
	}


	public function position_is_a_part_of_unit($position) {
		return ($this->start[0] <= $position[0] && $position[0] <= $this->start[0] + $this->span[0] &&
		$this->start[1] <= $position[1] && $position[1] <= $this->start[1] + $this->span[1]);
	}


	public static function unit_currently_holds_an_item($drawer, $unit) {
		$drawer = self::regex_drawer_indicator($drawer);
		$unit = self::regex_unit_indicator($unit);

		global $mysqli;
		if($results = $mysqli->query("SELECT *
										FROM `storage_box`
										WHERE `trans_id` IS NOT NULL
										AND `drawer` = '$drawer'
										AND `unit` = '$unit';"
		)) {
			if($results->num_rows == 0) return false;
		}
		return true;  // could not query or size not 0, so assume drawer holds item
	}



	public static function regex_unit_indicator($id) {
		return htmlspecialchars($id);
	}


	public static function regex_drawer_indicator($name) {
		return htmlspecialchars($name);
	}


	public static function regex_drawer_size($size) {
		return htmlspecialchars($size);
	}


	public static function regex_drawer_span($span) {
		return htmlspecialchars($span);
	}


	public static function regex_drawer_start($start) {
		return htmlspecialchars($start);
	}


	public static function regex_drawer_type($type) {
		return htmlspecialchars($type);
	}

}

?>