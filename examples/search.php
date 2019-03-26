<?php

include "../src/SonicClient.php";

$client = new SonicSearchSession('localhost', 1491, 'SecretPassword');
try {
	$client->connect();
} catch(SonicUnreachableException $e) {
	echo "Failed to connect to Sonic:";
	var_dump($e);
	exit;
}

// Ping sonic (to keep the session alive, e.g.)
$client->ping();

// Query Sonic
$results = $client->query('messages', 'default', 'Elasticsearch slow');
var_dump($results);

// Let Sonic suggest auto-completion for some words
var_dump($client->suggest('messages', 'default', 'So', 1));

?>
