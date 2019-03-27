<?php

include "../src/SonicClient.php";
define('__SONIC_CLIENT_DEBUG__', true);

$client = new SonicControlSession('localhost', 1491, 'SecretPassword');
try {
	$client->connect();
} catch(SonicUnreachableException $e) {
	echo "Failed to connect to Sonic:";
	var_dump($e);
	exit;
}

// Ping sonic (to keep the session alive, e.g.)
$client->ping();

// Trigger some action at Sonic
$client->trigger('consolidate');

?>
