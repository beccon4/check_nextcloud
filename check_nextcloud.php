#!/usr/bin/php
<?php

/***
 *
 * Monitoring plugin to check the status of nextcloud serverinfo app
 *
 * Copyright (c) 2019 Kevin Köllmann <mail@kevinkoellmann.de>
 *
 *
 * Usage: /usr/bin/php ./check_nextcloud.php -H cloud.example.com -u /ocs/v2.php/apps/serverinfo/api/v1/info
 *
 *
 * For more information visit https://github.com/koelle25/check_nextcloud
 *
 ***/


function convert_filesize($bytes, $decimals = 2) {
  $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), @$size[$factor]);
}

function show_helptext() {
  print "check_nextcloud.php - Monitoring plugin to check the status of nextcloud serverinfo app.\n
You need to specify the following parameters:
  -H:  hostname of the nextcloud instance, e.g. cloud.example.com
  -U:  uri of the nextcloud serverinfo api, you'll find it at https://cloud.example.com/settings/admin/serverinfo
  -T:  authenticate using serverinfo token (either -T or -u and -p)
  -u:  username to authenticate against the API endpoint
  -p:  password to authenticate against the API endpoint
  -s:  (optional) should the check be done over HTTPS? (default: true)  \n\n";
}



// get commands passed as arguments
$options = getopt("H:U:u:p:T:s::");
if (!is_array($options) ) {
  print "There was a problem reading the passed option.\n\n";
  exit(1);
}

if( !isset($options['H']) ) {
  show_helptext();
  exit(2);
}

$nchost = trim($options['H']);
$ncuri = isset($options['U']) ? trim($options['U']) : "/ocs/v2.php/apps/serverinfo/api/v1/info";
$ncssl = (isset($options['s']) && is_bool($options['s'])) ? $options['s'] : true;
$ncsit =  isset($options['T']) ? trim($options['T']) : "";
$ncuser = isset($options['u']) ? trim($options['u']) : "";
$ncpass = isset($options['p']) ? trim($options['p']) : "";

$context=null;
if($ncsit) {
  $opts = array (
    'http' => array (
    'method' => 'GET',
    'header' => "NC-Token:$ncsit",
    )
  );
  $context = stream_context_create($opts);
}
$ncurl = ($ncssl ? "https://" : "http://") . (isset($options['T']) ? "" : $ncuser . ":" . $ncpass . "@") . $nchost . $ncuri;

// get UUID from scan.nextcloud.com service
$url = "${ncurl}?format=json";
$res_str = file_get_contents($url, false, $context);
if(empty($res_str)) {
  print "Cannot access Nextcloud server info\n\n";
  exit(2);
}
$result = json_decode($res_str, true);

// collect performance data results for output
$statuscode = $result['ocs']['meta']['statuscode'];
$status = $result['ocs']['meta']['status'] . ": " . $result['ocs']['meta']['message'];
$nc_version = $result['ocs']['data']['nextcloud']['system']['version'];
$freespace = $result['ocs']['data']['nextcloud']['system']['freespace'];
$load1 = $result['ocs']['data']['nextcloud']['system']['cpuload']['0'];
$load5 = $result['ocs']['data']['nextcloud']['system']['cpuload']['1'];
$load15 = $result['ocs']['data']['nextcloud']['system']['cpuload']['2'];
$mem_free = $result['ocs']['data']['nextcloud']['system']['mem_free'] * 1024;
$mem_total = $result['ocs']['data']['nextcloud']['system']['mem_total'] * 1024;
$swap_free = $result['ocs']['data']['nextcloud']['system']['swap_free'] * 1024;
$swap_total = $result['ocs']['data']['nextcloud']['system']['swap_total'] * 1024;
$app_updates_available = $result['ocs']['data']['nextcloud']['system']['apps']['num_updates_available'];
$app_updates = array_keys($result['ocs']['data']['nextcloud']['system']['apps']['app_updates']);
$users = $result['ocs']['data']['nextcloud']['storage']['num_users'];
$users_active_5min = $result['ocs']['data']['activeUsers']['last5minutes'];
$users_active_1h = $result['ocs']['data']['activeUsers']['last1hour'];
$users_active_24h = $result['ocs']['data']['activeUsers']['last24hours'];
$files = $result['ocs']['data']['nextcloud']['storage']['num_files'];
$shares = $result['ocs']['data']['nextcloud']['shares']['num_shares'];
$shares_user = $result['ocs']['data']['nextcloud']['shares']['num_shares_user'];
$shares_groups = $result['ocs']['data']['nextcloud']['shares']['num_shares_groups'];
$shares_link = $result['ocs']['data']['nextcloud']['shares']['num_shares_link'];
$shares_fed = $result['ocs']['data']['nextcloud']['shares']['num_fed_shares_sent'];
$webserver = $result['ocs']['data']['server']['webserver'];
$php_version = $result['ocs']['data']['server']['php']['version'];
$db = $result['ocs']['data']['server']['database']['type'] . " " . $result['ocs']['data']['server']['database']['version'];
$db_size = $result['ocs']['data']['server']['database']['size'];

// print output for icinga
if ($statuscode == 200) {
  $status = 'OK';
  $returncode = 0;
  if ($app_updates_available > 0) {
    $status = 'WARNING';
    $returncode = 1;
  }
  printf("%s - Nextcloud %s (%s available), ", $status, $nc_version, convert_filesize($freespace));
  if ($app_updates_available > 0) {
    printf("%d app updates available (%s), ", $app_updates_available, implode(", ", $app_updates));
  }
  printf("%d users (%d < 5min, %d < 1h, %d < 24h), %d files, ", $users, $users_active_5min, $users_active_1h, $users_active_24h, $files);
  printf("%d shares (%d user, %d group, %d link, %d federated), ", $shares, $shares_user, $shares_groups, $shares_link, $shares_fed);
  printf("%s, PHP %s, %s (%s)", $webserver, $php_version, $db, convert_filesize($db_size));
  echo "| free_space=${freespace}B ";
  echo "load1=${load1} ";
  echo "load5=${load5} ";
  echo "load15=${load15} ";
  echo "mem_free=${mem_free}B;;;0;${mem_total} ";
  echo "swap_free=${swap_free}B;;;0;${swap_total} ";
  echo "app_updates=${app_updates_available} ";
  echo "users=${users} ";
  echo "users5m=${users_active_5min} ";
  echo "users1h=${users_active_1h} ";
  echo "users24h=${users_active_24h} ";
  echo "files=${files} ";
  echo "shares=${shares} ";
  echo "db_size=${db_size}B ";
  echo "\n";
  exit($returncode);
} else if ($statuscode >= 400 && $statuscode < 600) {
  echo "CRITICAL: $status\n";
  exit(2);
} else {
  echo "WARNING: $status\n";
  exit(1);
}
?>
