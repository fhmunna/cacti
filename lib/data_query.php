<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004 Ian Berry                                            |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | cacti: a php-based graphing solution                                    |
 +-------------------------------------------------------------------------+
 | Most of this code has been designed, written and is maintained by       |
 | Ian Berry. See about.php for specific developer credit. Any questions   |
 | or comments regarding this code should be directed to:                  |
 | - iberry@raxnet.net                                                     |
 +-------------------------------------------------------------------------+
 | - raXnet - http://www.raxnet.net/                                       |
 +-------------------------------------------------------------------------+
*/

function run_data_query($host_id, $snmp_query_id) {
	global $config;

	include_once($config["library_path"] . "/poller.php");
	include_once($config["library_path"] . "/utility.php");

	debug_log_insert("data_query", "Running data query [$snmp_query_id].");
	$type_id = db_fetch_cell("select data_input.type_id from snmp_query,data_input where snmp_query.data_input_id=data_input.id and snmp_query.id=$snmp_query_id");

	if ($type_id == DATA_INPUT_TYPE_SNMP_QUERY) {
		debug_log_insert("data_query", "Found type = '3' [snmp query].");
		$result = query_snmp_host($host_id, $snmp_query_id);
	}elseif ($type_id == DATA_INPUT_TYPE_SCRIPT_QUERY) {
		debug_log_insert("data_query", "Found type = '4 '[script query].");
		$result = query_script_host($host_id, $snmp_query_id);
	}elseif ($type_id == DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER) {
		debug_log_insert("data_query", "Found type = '6 '[script query].");
		$result = query_script_host($host_id, $snmp_query_id);
	}else{
		debug_log_insert("data_query", "Unknown type = '$type_id'");
	}

	/* update the sort cache */
	update_data_query_sort_cache($host_id, $snmp_query_id);

	/* update the auto reindex cache */
	update_reindex_cache($host_id, $snmp_query_id);

	/* update the the "local" data query cache */
	update_data_query_cache($host_id, $snmp_query_id);

	/* update the poller cache */
	update_poller_cache_from_query($host_id, $snmp_query_id);

	return (isset($result) ? $result : true);
}

function get_data_query_array($snmp_query_id) {
	global $config;

	include_once($config["library_path"] . "/xml.php");

	$xml_file_path = db_fetch_cell("select xml_path from snmp_query where id=$snmp_query_id");
	$xml_file_path = str_replace("<path_cacti>", $config["base_path"], $xml_file_path);

	if (!file_exists($xml_file_path)) {
		debug_log_insert("data_query", "Could not find data query XML file at '$xml_file_path'");
		return false;
	}

	debug_log_insert("data_query", "Found data query XML file at '$xml_file_path'");

	$data = implode("",file($xml_file_path));
	return xml2array($data);
}

function query_script_host($host_id, $snmp_query_id) {
	$script_queries = get_data_query_array($snmp_query_id);

	if ($script_queries == false) {
		debug_log_insert("data_query", "Error parsing XML file into an array.");
		return false;
	}

	debug_log_insert("data_query", "XML file parsed ok.");

	if (isset($script_queries["script_server"])) {
		$script_queries["script_path"] = "|path_php_binary| -q " . $script_queries["script_path"];
	}

	$script_path = get_script_query_path((isset($script_queries["arg_prepend"]) ? $script_queries["arg_prepend"] . " ": "") . $script_queries["arg_index"], $script_queries["script_path"], $host_id);

	/* fetch specified index at specified OID */
	$script_index_array = exec_into_array($script_path);

	debug_log_insert("data_query", "Executing script for list of indexes '$script_path'");

	db_execute("delete from host_snmp_cache where host_id=$host_id and snmp_query_id=$snmp_query_id");

	while (list($field_name, $field_array) = each($script_queries["fields"])) {
		if ($field_array["direction"] == "input") {
			$script_path = get_script_query_path((isset($script_queries["arg_prepend"]) ? $script_queries["arg_prepend"] . " ": "") . $script_queries["arg_query"] . " " . $field_array["query_name"], $script_queries["script_path"], $host_id);

			$script_data_array = exec_into_array($script_path);

			debug_log_insert("data_query", "Executing script query '$script_path'");

			for ($i=0;($i<sizeof($script_data_array));$i++) {
				if (preg_match("/(.*)" . preg_quote($script_queries["output_delimeter"]) . "(.*)/", $script_data_array[$i], $matches)) {
					$script_index = $matches[1];
					$field_value = $matches[2];

					db_execute("replace into host_snmp_cache
						(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
						values ($host_id,$snmp_query_id,'$field_name','$field_value','$script_index','')");

					debug_log_insert("data_query", "Found item [$field_name='$field_value'] index: $script_index");
				}
			}
		}
	}

	return true;
}

function query_snmp_host($host_id, $snmp_query_id) {
	global $config;

	include_once($config["library_path"] . "/snmp.php");

	$host = db_fetch_row("select hostname,snmp_community,snmp_version,snmp_username,snmp_password,snmp_port,snmp_timeout from host where id=$host_id");

	$snmp_queries = get_data_query_array($snmp_query_id);

	if ((empty($host["hostname"])) || ($snmp_queries == false)) {
		debug_log_insert("data_query", "Error parsing XML file into an array.");
		return false;
	}

	debug_log_insert("data_query", "XML file parsed ok.");

	/* fetch specified index at specified OID */
	$snmp_index = cacti_snmp_walk($host["hostname"], $host["snmp_community"], $snmp_queries["oid_index"], $host["snmp_version"], $host["snmp_username"], $host["snmp_password"], $host["snmp_port"], $host["snmp_timeout"], SNMP_WEBUI);

	debug_log_insert("data_query", "Executing SNMP walk for list of indexes @ '" . $snmp_queries["oid_index"] . "'");

	/* no data found; get out */
	if (!$snmp_index) {
		debug_log_insert("data_query", "No SNMP data returned");
		return false;
	}

	db_execute("delete from host_snmp_cache where host_id=$host_id and snmp_query_id=$snmp_query_id");

	while (list($field_name, $field_array) = each($snmp_queries["fields"])) {
		if (($field_array["method"] == "get") && ($field_array["direction"] == "input")) {
			debug_log_insert("data_query", "Located input field '$field_name' [get]");

			if ($field_array["source"] == "value") {
				for ($i=0;($i<sizeof($snmp_index));$i++) {
					$oid = $field_array["oid"] .  "." . $snmp_index[$i]["value"];

					$value = cacti_snmp_get($host["hostname"], $host["snmp_community"], $oid, $host["snmp_version"], $host["snmp_username"], $host["snmp_password"], $host["snmp_port"], $host["snmp_timeout"], SNMP_WEBUI);

					debug_log_insert("data_query", "Executing SNMP get for data @ '$oid' [value='$value']");

					db_execute("replace into host_snmp_cache
						(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
						values ($host_id,$snmp_query_id,'$field_name','$value'," . $snmp_index[$i]["value"] . ",'$oid')");
				}
			}
		}elseif (($field_array["method"] == "walk") && ($field_array["direction"] == "input")) {
			debug_log_insert("data_query", "Located input field '$field_name' [walk]");

			$snmp_data = cacti_snmp_walk($host["hostname"], $host["snmp_community"], $field_array["oid"], $host["snmp_version"], $host["snmp_username"], $host["snmp_password"], $host["snmp_port"], $host["snmp_timeout"], SNMP_WEBUI);

			debug_log_insert("data_query", "Executing SNMP walk for data @ '" . $field_array["oid"] . "'");

			if ($field_array["source"] == "value") {
				for ($i=0;($i<sizeof($snmp_data));$i++) {
					$snmp_index = ereg_replace('.*\.([0-9]+)$', "\\1", $snmp_data[$i]["oid"]);

					$oid = $field_array["oid"] . ".$snmp_index";

					if ($field_name == "ifOperStatus") {
						if ($snmp_data[$i]["value"] == "down(2)") $snmp_data[$i]["value"] = "Down";
						if ($snmp_data[$i]["value"] == "up(1)") $snmp_data[$i]["value"] = "Up";
					}

					debug_log_insert("data_query", "Found item [$field_name='" . $snmp_data[$i]["value"] . "'] index: $snmp_index [from value]");

					db_execute("replace into host_snmp_cache
						(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
						values ($host_id,$snmp_query_id,'$field_name','" . $snmp_data[$i]["value"] . "',$snmp_index,'$oid')");
				}
			}elseif (ereg("^OID/REGEXP:", $field_array["source"])) {
				for ($i=0;($i<sizeof($snmp_data));$i++) {
					$value = ereg_replace(ereg_replace("^OID/REGEXP:", "", $field_array["source"]), "\\1", $snmp_data[$i]["oid"]);

					if ((!isset($snmp_data[$i]["value"])) || ($snmp_data[$i]["value"] == "")) {
						/* do nothing */
					} else {
						$snmp_index = $snmp_data[$i]["value"];
					}

					/* correct bogus index value */
					/* found in some devices such as an EMC Cellera */
					if ($snmp_index == 0) {
						$snmp_index = 1;
					}

					$oid = $field_array["oid"] .  "." . $value;

					debug_log_insert("data_query", "Found item [$field_name='$value'] index: $snmp_index [from regexp oid parse]");

					db_execute("replace into host_snmp_cache
						(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
						values ($host_id,$snmp_query_id,'$field_name','$value',$snmp_index,'$oid')");
				}
			}elseif (ereg("^VALUE/REGEXP:", $field_array["source"])) {
				for ($i=0;($i<sizeof($snmp_data));$i++) {
					$value = ereg_replace(ereg_replace("^VALUE/REGEXP:", "", $field_array["source"]), "\\1", $snmp_data[$i]["value"]);
					$snmp_index = ereg_replace('.*\.([0-9]+)$', "\\1", $snmp_data[$i]["oid"]);
					$oid = $field_array["oid"] .  "." . $value;

					debug_log_insert("data_query", "Found item [$field_name='$value'] index: $snmp_index [from regexp value parse]");

					db_execute("replace into host_snmp_cache
						(host_id,snmp_query_id,field_name,field_value,snmp_index,oid)
						values ($host_id,$snmp_query_id,'$field_name','$value',$snmp_index,'$oid')");
				}
			}
		}
	}

	return true;
}

/* data_query_index - returns an array containing the data query ID and index value given
	a data query index type/value combination and a host ID
   @arg $index_type - the name of the index to match
   @arg $index_value - the value of the index to match
   @arg $host_id - (int) the host ID to match
   @arg $data_query_id - (int) the data query ID to match
   @returns - (array) the data query ID and index that matches the three arguments */
function data_query_index($index_type, $index_value, $host_id, $data_query_id) {
	return db_fetch_cell("select
		host_snmp_cache.snmp_index
		from host_snmp_cache
		where host_snmp_cache.field_name='$index_type'
		and host_snmp_cache.field_value='" . addslashes($index_value) . "'
		and host_snmp_cache.host_id='$host_id'
		and host_snmp_cache.snmp_query_id='$data_query_id'");
}

/* data_query_field_list - returns an array containing data query information for a given data source
   @arg $data_template_data_id - the ID of the data source to retrieve information for
   @returns - (array) an array that looks like:
	Array
	(
	   [index_type] => ifIndex
	   [index_value] => 3
	   [output_type] => 13
	) */
function data_query_field_list($data_template_data_id) {
	$field = db_fetch_assoc("select
		data_input_fields.type_code,
		data_input_data.value
		from data_input_fields,data_input_data
		where data_input_fields.id=data_input_data.data_input_field_id
		and data_input_data.data_template_data_id=$data_template_data_id
		and (data_input_fields.type_code='index_type' or data_input_fields.type_code='index_value' or data_input_fields.type_code='output_type')");
	$field = array_rekey($field, "type_code", "value");

	if ((!isset($field["index_type"])) || (!isset($field["index_value"])) || (!isset($field["output_type"]))) {
		return 0;
	}else{
		return $field;
	}
}

/* encode_data_query_index - encodes a data query index value so that it can be included
	inside of a form
   @arg $index - the index name to encode
   @returns - the encoded data query index */
function encode_data_query_index($index) {
	return md5($index);
}

/* decode_data_query_index - decodes a data query index value so that it can be read from
	a form
   @arg $encoded_index - the index that was encoded with encode_data_query_index()
   @arg $data_query_id - the id of the data query that this index belongs to
   @arg $encoded_index - the id of the host that this index belongs to
   @returns - the decoded data query index */
function decode_data_query_index($encoded_index, $data_query_id, $host_id) {
	/* yes, i know MySQL has a MD5() function that would make this a bit quicker. however i would like to
	keep things abstracted for now so Cacti works with ADODB fully when i get around to porting my db calls */
	$indexes = db_fetch_assoc("select snmp_index from host_snmp_cache where host_id=$host_id and snmp_query_id=$data_query_id  group by snmp_index");

	if (sizeof($indexes) > 0) {
	foreach ($indexes as $index) {
		if (encode_data_query_index($index["snmp_index"]) == $encoded_index) {
			return $index["snmp_index"];
		}
	}
	}
}

/* update_data_query_cache - updates the local data query cache for each graph and data
     source tied to this host/data query
   @arg $host_id - the id of the host to refresh
   @arg $data_query_id - the id of the data query to refresh */
function update_data_query_cache($host_id, $data_query_id) {
	$graphs = db_fetch_assoc("select id from graph_local where host_id = '$host_id' and snmp_query_id = '$data_query_id'");

	if (sizeof($graphs) > 0) {
		foreach ($graphs as $graph) {
			update_graph_data_query_cache($graph["id"]);
		}
	}

	$data_sources = db_fetch_assoc("select id from data_local where host_id = '$host_id' and snmp_query_id = '$data_query_id'");

	if (sizeof($data_sources) > 0) {
		foreach ($data_sources as $data_source) {
			update_data_source_data_query_cache($data_source["id"]);
		}
	}
}

/* update_graph_data_query_cache - updates the local data query cache for a particular
	graph
   @arg $local_graph_id - the id of the graph to update the data query cache for */
function update_graph_data_query_cache($local_graph_id) {
	$host_id = db_fetch_cell("select host_id from graph_local where id=$local_graph_id");

	$field = data_query_field_list(db_fetch_cell("select
		data_template_data.id
		from graph_templates_item,data_template_rrd,data_template_data
		where graph_templates_item.task_item_id=data_template_rrd.id
		and data_template_rrd.local_data_id=data_template_data.local_data_id
		and graph_templates_item.local_graph_id=$local_graph_id
		limit 0,1"));

	if (empty($field)) { return; }

	$data_query_id = db_fetch_cell("select snmp_query_id from snmp_query_graph where id='" . $field["output_type"] . "'");

	$index = data_query_index($field["index_type"], $field["index_value"], $host_id, $data_query_id);

	if (($data_query_id != "0") && ($index != "")) {
		db_execute("update graph_local set snmp_query_id='$data_query_id',snmp_index='$index' where id=$local_graph_id");

		/* update graph title cache */
		update_graph_title_cache($local_graph_id);
	}
}

/* update_data_source_data_query_cache - updates the local data query cache for a particular
	data source
   @arg $local_data_id - the id of the data source to update the data query cache for */
function update_data_source_data_query_cache($local_data_id) {
	$host_id = db_fetch_cell("select host_id from data_local where id=$local_data_id");

	$field = data_query_field_list(db_fetch_cell("select
		data_template_data.id
		from data_template_data
		where data_template_data.local_data_id=$local_data_id"));

	if (empty($field)) { return; }

	$data_query_id = db_fetch_cell("select snmp_query_id from snmp_query_graph where id='" . $field["output_type"] . "'");

	$index = data_query_index($field["index_type"], $field["index_value"], $host_id, $data_query_id);

	if (($data_query_id != "0") && ($index != "")) {
		db_execute("update data_local set snmp_query_id='$data_query_id',snmp_index='$index' where id='$local_data_id'");

		/* update data source title cache */
		update_data_source_title_cache($local_data_id);
	}
}

/* get_formatted_data_query_indexes - obtains a list of indexes for a host/data query that
	is sorted by the chosen index field and formatted using the data query index title
	format
   @arg $host_id - the id of the host which contains the data query
   @arg $data_query_id - the id of the data query to retrieve a list of indexes for
   @returns - an array formatted like the following:
	$arr[snmp_index] = "formatted data query index string" */
function get_formatted_data_query_indexes($host_id, $data_query_id) {
	global $config;

	include_once($config["library_path"] . "/sort.php");

	if (empty($data_query_id)) {
		return array("" => "Unknown Index");
	}

	/* from the xml; cached in 'host_snmp_query' */
	$sort_cache = db_fetch_row("select sort_field,title_format from host_snmp_query where host_id='$host_id' and snmp_query_id='$data_query_id'");

	/* get a list of data query indexes and the field value that we are supposed
	to sort */
	$sort_field_data = array_rekey(db_fetch_assoc("select
		graph_local.snmp_index,
		host_snmp_cache.field_value
		from graph_local,host_snmp_cache
		where graph_local.host_id=host_snmp_cache.host_id
		and graph_local.snmp_query_id=host_snmp_cache.snmp_query_id
		and graph_local.snmp_index=host_snmp_cache.snmp_index
		and graph_local.snmp_query_id=$data_query_id
		and graph_local.host_id=$host_id
		and host_snmp_cache.field_name='" . $sort_cache["sort_field"] . "'
		group by graph_local.snmp_index"), "snmp_index", "field_value");

	/* sort the data using the "data query index" sort algorithm */
	uasort($sort_field_data, "usort_data_query_index");

	$sorted_results = array();

	while (list($snmp_index, $sort_field_value) = each($sort_field_data)) {
		$sorted_results[$snmp_index] = substitute_snmp_query_data($sort_cache["title_format"], $host_id, $data_query_id, $snmp_index);
	}

	return $sorted_results;
}

/* get_formatted_data_query_index - obtains a single index for a host/data query/data query
	index that is formatted using the data query index title format
   @arg $host_id - the id of the host which contains the data query
   @arg $data_query_id - the id of the data query which contains the data query index
   @arg $data_query_index - the index to retrieve the formatted name for
   @returns - a string containing the formatted name for the given data query index */
function get_formatted_data_query_index($host_id, $data_query_id, $data_query_index) {
	/* from the xml; cached in 'host_snmp_query' */
	$sort_cache = db_fetch_row("select sort_field,title_format from host_snmp_query where host_id='$host_id' and snmp_query_id='$data_query_id'");

	return substitute_snmp_query_data($sort_cache["title_format"], $host_id, $data_query_id, $data_query_index);
}

/* get_ordered_index_type_list - builds an ordered list of data query index types that are
	valid given a list of data query indexes that will be checked against the data query
	cache
   @arg $host_id - the id of the host which contains the data query
   @arg $data_query_id - the id of the data query to build the type list from
   @arg $data_query_index_array - an array containing each data query index to use when checking
	each data query type for validity. a valid data query type will contain no empty or duplicate
	values for each row in the cache that matches one of the $data_query_index_array
   @returns - an array of data query types either ordered or unordered depending on whether
	the xml file has a manual ordering preference specified */
function get_ordered_index_type_list($host_id, $data_query_id, $data_query_index_array = array()) {
	$raw_xml = get_data_query_array($data_query_id);

	$xml_outputs = array();

	/* create an SQL string that contains each index in this snmp_index_id */
	$sql_or = array_to_sql_or($data_query_index_array, "snmp_index");

	/* list each of the input fields for this snmp query */
	while (list($field_name, $field_array) = each($raw_xml["fields"])) {
		if ($field_array["direction"] == "input") {
			/* create a list of all values for this index */
			if (sizeof($data_query_index_array) == 0) {
				$field_values = db_fetch_assoc("select field_value from host_snmp_cache where host_id=$host_id and snmp_query_id=$data_query_id and field_name='$field_name'");
			}else{
				$field_values = db_fetch_assoc("select field_value from host_snmp_cache where host_id=$host_id and snmp_query_id=$data_query_id and field_name='$field_name' and $sql_or");
			}

			/* aggregate the above list so there is no duplicates */
			$aggregate_field_values = array_rekey($field_values, "field_value", "field_value");

			/* fields that contain duplicate or empty values are not suitable to index off of */
			if (!((sizeof($aggregate_field_values) < sizeof($field_values)) || (in_array("", $aggregate_field_values) == true) || (sizeof($aggregate_field_values) == 0))) {
				array_push($xml_outputs, $field_name);
			}
		}
	}

	$return_array = array();

	/* the xml file contains an ordered list of "indexable" fields */
	if (isset($raw_xml["index_order"])) {
		$index_order_array = explode(":", $raw_xml["index_order"]);

		for ($i=0; $i<count($index_order_array); $i++) {
			if (in_array($index_order_array[$i], $xml_outputs)) {
				$return_array{$index_order_array[$i]} = $index_order_array[$i] . " (" . $raw_xml["fields"]{$index_order_array[$i]}["name"] . ")";
			}
		}
	/* the xml file does not contain a field list, ignore the order */
	}else{
		for ($i=0; $i<count($xml_outputs); $i++) {
			$return_array{$xml_outputs[$i]} = $xml_outputs[$i] . " (" . $raw_xml["fields"]{$xml_outputs[$i]}["name"] . ")";
		}
	}

	return $return_array;
}

/* update_data_query_sort_cache - updates the sort cache for a particular host/data query
	combination. this works by fetching a list of valid data query index types and choosing
	the first one in the list. the user can optionally override how the cache is updated
	in the data query xml file
   @arg $host_id - the id of the host which contains the data query
   @arg $data_query_id - the id of the data query update the sort cache for */
function update_data_query_sort_cache($host_id, $data_query_id) {
	$raw_xml = get_data_query_array($data_query_id);

	/* get a list of valid data query types */
	$valid_index_types = get_ordered_index_type_list($host_id, $data_query_id);

	/* something is probably wrong with the data query */
	if (sizeof($valid_index_types) == 0) {
		$sort_field = "";
	}else{
		/* grab the first field off the list */
		list($sort_field, $sort_field_formatted) = each($valid_index_types);
	}

	/* substitute variables */
	if (isset($raw_xml["index_title_format"])) {
		$title_format = str_replace("|chosen_order_field|", "|query_$sort_field|", $raw_xml["index_title_format"]);
	}else{
		$title_format = "|query_$sort_field|";
	}

	/* update the cache */
	db_execute("update host_snmp_query set sort_field = '$sort_field', title_format = '$title_format' where host_id = '$host_id' and snmp_query_id = '$data_query_id'");
}

/* update_data_query_sort_cache_by_host - updates the sort cache for all data queries associated
	with a particular host. see update_data_query_sort_cache() for details about updating the cache
   @arg $host_id - the id of the host to update the cache for */
function update_data_query_sort_cache_by_host($host_id) {
	$data_queries = db_fetch_assoc("select snmp_query_id from host_snmp_query where host_id = '$host_id'");

	if (sizeof($data_queries) > 0) {
		foreach ($data_queries as $data_query) {
			update_data_query_sort_cache($host_id, $data_query["snmp_query_id"]);
		}
	}
}

/* get_best_data_query_index_type - returns the best available data query index type using the
	sort cache
   @arg $host_id - the id of the host which contains the data query
   @arg $data_query_id - the id of the data query to fetch the best data query index type for
   @returns - a string containing containing best data query index type. this will be one of the
	valid input field names as specified in the data query xml file */
function get_best_data_query_index_type($host_id, $data_query_id) {
	return db_fetch_cell("select sort_field from host_snmp_query where host_id = '$host_id' and snmp_query_id = '$data_query_id'");
}

/* get_script_query_path - builds the complete script query executable path
   @arg $args - the variable that contains any arguments to be appended to the argument
	list (variables will be substituted in this function)
   @arg $script_path - the path on the disk to the script file
   @arg $host_id - the id of the host that this script query belongs to
   @returns - a full path to the script query script containing all arguments */
function get_script_query_path($args, $script_path, $host_id) {
	global $config;

	include_once($config["library_path"] . "/variables.php");

	/* get any extra arguments that need to be passed to the script */
	if (!empty($args)) {
		$extra_arguments = substitute_host_data($args, "|", "|", $host_id);
	}else{
		$extra_arguments = "";
	}

	/* get a complete path for out target script */
	return substitute_script_query_path($script_path) . " $extra_arguments";
}

?>