<?php

abstract class SonicException extends Exception {};
class SonicUnreachableException extends SonicException {
	public function __construct($errno, $errmsg) {
		parent::__construct("Could not connect to Sonic [$errno]: $errmsg");
	}
};
class SonicAuthenticationRequiredException extends SonicException {};
class SonicAuthenticationFailedException extends SonicException {};
class SonicProtocolError extends SonicException {};
class SonicConnectionLostException extends SonicException {};
class SonicModeChangeFailed extends SonicException {};
class SonicCommandFailedException extends SonicException {
	public function __construct(SonicMessage &$request, SonicMessage &$response) {
		parent::__construct("Request: ". $request->serialize() .
							" failed:\nResponse: ". $response->serialize() . "\n");
	}
};


/**
 * Wrapper for constructing, serializing and deserializing of messages to and from Sonic.
 */
class SonicMessage {

	private $segments = null;

	public function __construct(array $segments) {
		$this->segments = $segments;
	}
	public static function fromStr(string $message) : SonicMessage {
		return new SonicMessage(explode(' ', trim($message)));
	}
	public function serialize() : string {
		return join($this->segments, ' ');
	}
	
	public function getVerb() : string {
		return $this->segments[0];
	}
	public function setVerb(string $verb) {
		$this->segments[0] = $verb;
	}
		
	public function getArgument(int $idx) : string {
		return $this->segments[$idx + 1];
	}
	public function getArgumentInt(int $idx) : int {
		return intval($this->getArgument($idx));
	}
	
	public function setArgument(int $idx, string $value) {
		$this->segments[$idx + 1] = $value;
	}
	public function setArgumentInt(int $idx, int $value) {
		$this->setArgument($idx, (string) $value);
	}
	public function setArgumentKeyVal(int $idx, string $key, string $value) {
		$this->setArgument($idx, "$key($value)");
	}
	
	public function asArgumentList(int $startIdx) : SonicArgumentList {
		return SonicArgumentList::fromMessage($this->segments, $startIdx + 1);
	}
	public function asArray(int $startIdx) : array {
		return array_slice($this->segments, $startIdx + 1);
	}
	
	public static function sanitizeValue(string &$value) : string {
		return preg_replace('/[\r\n\t"]/', ' ', $value);
	}
	public static function quoted(string &$value) : string {
		return '"' . $value . '"';
	}
	
	public function length() : int {
		$result = 0;
		foreach($this->segments as $segment) {
			$result += strlen($segment);
		}
		return $result + count($this->segments) - 1;
	}
	public function argumentCnt() : int {
		return count($this->segments) - 1;
	}
}

/**
 * Small helper to parse argument lists from a Sonic response.
 */
class SonicArgumentList {

	private $arguments;
	
	public function __construct(array &$arguments) {
		$this->arguments = $arguments;
	}
	/**
	 * Parse the given message segment array, starting at a given index as argument list.
	 * @param message string[] Array of message segments to parse as argument list.
	 * @param startIdx int Index at which to start parsing within the given message segment list.
	 * @return SonicArgumentList Parsed argument list.
	 */
	public static function fromMessage(array &$message, int $startIdx) : SonicArgumentList {
		$result = [];
		for($i = $startIdx; $i < count($message); ++$i) {
			$argParts = explode('(', trim($message[$i], ')'));
			assert(count($argParts) == 2);
			$result[$argParts[0]] = $argParts[1];
		}
		return new SonicArgumentList($result);
	}
	
	public function getArgument(string $key, string $default = null) : string {
		if(!array_key_exists($key, $this->arguments)) { return $default; }
		return $this->arguments[$key];
	}
	public function getArgumentInt(string $key, int $default = -1) : int {
		if(!array_key_exists($key, $this->arguments)) { return $default; }
		return intval($this->arguments[$key]);
	}

}

/**
 * Base class for Sonic sessions. This class should be overloaded once per possible mode/session.
 * There, for example, are overloads for Search and Ingest.
 */
abstract class AbstractSonicSessionBase {

	private $host;
	private $port;
	private $password;
	private $mode;
	
	// connection status
	private $socket = null;
	private $receiveBufferSize = 8192;
	
	
	public function __construct(string $host, int $port, string $password, string $mode) {
		$this->host = $host;
		$this->port = $port;
		$this->password = $password;
		$this->mode = $mode;
	}
	
	public function __destruct() {
		if($this->socket) {
			$this->sendMessage(new SonicMessage(['QUIT']));
			stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
		}
	}
	
	/**
	 * Connect to the Sonic-Server and switch to the corresponding session mode.
	 * @throws SonicUnreachableException If connecting to the sonic instance failed.
	 * @throws SonicAuthenticationRequiredException If no password was given, but Sonic required one.
	 * @throws SonicAuthenticationFailedException If the given password was wrong.
	 * @throws SonicModeChangeFailed Switching to the corresponding mode failed (for which reason ever).
	 * @throws SonicProtocolError If Sonic misbehaved or announced an unsupported protocol version.
	 */
	public function connect() {
		// Connect and parse greeting from Sonic
		if($this->socket != null) { return; }
		{
			$errno = 0; $errmsg = "";
			$this->socket = stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errmsg, 10);
			if(!$this->socket) { throw new SonicUnreachableException($errno, $errmsg); }
		}
		$helloMessage = $this->readResponse();
		if($helloMessage->getVerb() != 'CONNECTED') { throw new SonicProtocolError("Sonic did not greet us."); }
		
		// Start session
		$response = $this->sendAndAwaitResponse(new SonicMessage(['START', $this->mode, $this->password]));
		if($response->getVerb() != 'STARTED') {
			$reason = $response->getArgument(0);
			if($reason == 'authentication_required') {
				throw new SonicAuthenticationRequiredException("");
			} else if($reason == 'authentication_failed') {
				throw new SonicAuthenticationFailedException("");
			}
			throw new SonicModeChangeFailed("Sonic Mode-Change failed: ". $response->serialize());
		}
		$arguments = $response->asArgumentList(1);
		$this->receiveBufferSize = $arguments->getArgumentInt('buffer', 8192);
		var_dump($arguments->getArgumentInt('protocol', -1));
		if($arguments->getArgumentInt('protocol', -1) != 1) {
			throw new SonicProtocolError("Sonic instance announced unsupported protocol version.");
		}
	}
	
	
	/* ################## */
	/* # SEND / RECEIVE # */
	/* ################## */
	
	/**
	 * Send the given message and directly await and parse Sonic's response to it.
	 * @param SonicMessage message The message to send to Sonic.
	 * @return SonicMessage The parsed message Sonic sent us.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 */
	protected function sendAndAwaitResponse(SonicMessage $message) : SonicMessage {
		$this->sendMessage($message);
		return $this->readResponse();
	}
	
	/**
	 * Send the given message to Sonic.
	 * @param SonicMessage message The message to send to Sonic.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 */
	protected function sendMessage(SonicMessage $message) {
		$messageStr = $message->serialize() . "\n";
		$result = fputs($this->socket, $messageStr);
		if($result === false) { throw new SonicConnectionLostException(""); }
		echo("[SENT]: " . $messageStr);
	}
	
	/**
	 * Read one response line from the connection to Sonic.
	 * @return SonicMessage The parsed message Sonic sent us.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 */
	protected function readResponse() : SonicMessage {
		$response = stream_get_line($this->socket, $this->receiveBufferSize, "\n");
		echo("[RECV]: " . $response . "\n");
		if($response === false) { throw new SonicConnectionLostException(""); }
		return SonicMessage::fromStr($response);
	}
	
	/**
	 * Intelligently split the given value so that each chunk fits into the remaining buffer space.
	 * The value is split at spaces within the string.
	 * @param string The value to split into chunks (if necessary).
	 * @param int usedBuffer The amount of buffer-spaced that is already used.
	 * @return string[] Given big value string split up into chunks that each fit into the buffer.
	 * @throws SonicValueChunkingFailed If the string contained one word that is larger than the free buffer space.
	 */
	protected function splitValue(string &$value, int $usedBuffer) : array {
		$value = SonicMessage::sanitizeValue($value);
		$result = [];
		$maxMessageLength = $this->receiveBufferSize - $usedBuffer - 3; //Space in before, and quotes = 3
		$ptr = 0;
		while($ptr < strlen($value)) {
			$remaining = strlen($value) - $ptr;
			$segmentEnd = min($remaining, $maxMessageLength);
			$lastSplitterInSegment = $ptr + $segmentEnd;
			if($remaining > $maxMessageLength) {
				$lastSplitterInSegment = strrpos($value, ' ', -(strlen($value) - $ptr - $segmentEnd));
				if($lastSplitterInSegment < $ptr) {
					throw new SonicValueChunkingFailed("Message contains words longer than the receiveBuffer.");
				}
			}
			$result[] = substr($value, $ptr, $lastSplitterInSegment - $ptr);
			$ptr = $lastSplitterInSegment + 1;
		}
		return $result;
	}
	
	
	
	/* ############ */
	/* # COMMANDS # */
	/* ############ */
	
	/**
	 * Ping the Sonic server and await its pong response.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function ping() {
		$pingMessage = new SonicMessage(['PING']);
		$response = $this->sendAndAwaitResponse($pingMessage);
		if($response->getVerb() != 'PONG'); {
			throw new SonicCommandFailedException($pingMessage, $response);
		}
	}

};

/**
 * Sonic session implementation for Sonic's ingest mode.
 */
class SonicIngestSession extends AbstractSonicSessionBase {

	public function __construct(string $host, int $port, string $password) {
		parent::__construct($host, $port, $password, 'ingest');
	}
	
	/**
	 * Push the given terms into Sonic's index at the given "path" (collection, bucket, object).
	 * @param collection string Collection within Sonic to push the given terms into.
	 * @param bucket string Optional bucket within the collection to push the given terms into.
	 * @param object string Optional object within the given bucket to push the given terms into.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function push(string $collection, string $bucket, string $object, string $terms) {
		$pushMessageTemplate = new SonicMessage([ 'PUSH', $collection, $bucket, $object ]);
		$valueSplits = $this->splitValue($terms, $pushMessageTemplate->length());
		foreach($valueSplits as $valueChunk) {
			$pushChunkMessage = $pushMessageTemplate;
			$pushChunkMessage->setArgument(3, SonicMessage::quoted($valueChunk));
			$response = $this->sendAndAwaitResponse($pushChunkMessage);
			if($response->getVerb() != 'OK') {
				throw new SonicCommandFailedException($pushChunkMessage, $response);
			}
		}
	}
	
	/**
	 * Pop-Search the given terms from Sonic's index with the given "path" (collection, bucket, object).
	 * @param collection string Collection within Sonic to pop-search the given terms from.
	 * @param bucket string Optional bucket within the collection to pop-search the given terms from.
	 * @param object string Optional object within the given bucket to pop-search the given terms from.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function pop(string $collection, string $bucket, string $object, string $terms) : int {
		$result = 0;
		$popMessageTemplate = new SonicMessage([ 'POP', $collection, $bucket, $object ]);
		$valueSplits = $this->splitValue($terms, $popMessageTemplate->length());
		foreach($valueSplits as $valueChunk) {
			$popChunkMessage = $popMessageTemplate;
			$popChunkMessage->setArgument(3, SonicMessage::quoted($valueChunk));
			$response = $this->sendMessage($popChunkMessage);
			if($response->getVerb() != 'RESULT') {
				throw new SonicCommandFailedException($popChunkMessage, $response);
			}
			$result += $response->getArgumentInt(0);
		}
		return $result;
	}
	
	/**
	 * Count the amount of terms within the given "path" (collection, [bucket, [object]]) in Sonic's index.
	 * @param collection string Collection within Sonic to count terms in.
	 * @param bucket string Optional bucket within the collection to count terms in.
	 * @param object string Optional object within the given bucket to count terms in.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function count(string $collection, string $bucket = null, string $object = null) : int {
		$countMessage = new SonicMessage(['COUNT', $collection]);
		if($bucket != null) {
			$countMessage->setArgument(1, $bucket);
			if($object != null) { $countMessage->setArgument(2, $object); }
		}
		$response = $this->sendAndAwaitResponse($countMessage);
		if($response->getVerb() != 'RESULT') {
			throw new SonicCommandFailedException($countMessage, $response);
		}
		return $response->getArgumentInt(0);
	}
	
	/**
	 * Flush the given "path" (collection, [bucket, [object]]) in Sonic's index.
	 * @param collection string Collection within Sonic to flush.
	 * @param bucket string Optional bucket within the collection to flush.
	 * @param object string Optional object within the given bucket to flush.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function flush(string $collection, string $bucket = null, string $object = null) : int {
		$flushOp = 'FLUSHC';
		$flushMessage = new SonicMessage(['', $collection]);
		if($bucket != null) {
			$flushMessage->setArgument(1, $bucket);
			if($object == null) {
				$flushOp = 'FLUSHB';
			} else {
				$flushOp = 'FLUSHO';
				$flushMessage->setArgument(2, $object);
			}
		}
		$flushMessage->setVerb($flushOp);
		$response = $this->sendAndAwaitResponse($flushMessage);
		if($response->getVerb() != 'RESULT') {
			throw new SonicCommandFailedException($flushMessage, $response);
		}
		return $response->getArgumentInt(0);
	}
};

/**
 * Sonic session implementation for Sonic's search mode.
 */
class SonicSearchSession extends AbstractSonicSessionBase {

	public function __construct(string $host, int $port, string $password) {
		parent::__construct($host, $port, $password, 'search');
	}
	
	/**
	 * Query Sonic for the given search terms.
	 * @param collection string Collection within Sonic to query for the given search-term.
	 * @param bucket string Bucket within the collection to query for the given search-term.
	 * @param terms string A search string containing multiple words to query the given bucket for.
	 * @param limit int Optional limit to the amount of returned search results.
	 * @param offset int Optional offset in the pagination of search-results introduced by the limit.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function query(string $collection, string $bucket, string $terms, int $limit = null, int $offset = null) : array {
		$terms = SonicMessage::sanitizeValue($terms);
		$queryMessage = new SonicMessage(['QUERY', $collection, $bucket,
			SonicMessage::quoted($terms)
		]);
		if($limit != null) { $queryMessage->setArgumentKeyVal($queryMessage->argumentCnt(), 'LIMIT', $limit); }
		if($offset != null) { $queryMessage->setArgumentKeyVal($queryMessage->argumentCnt(), 'OFFSET', $offset); }
		$response = $this->sendAndAwaitResponse($queryMessage);
		if($response->getVerb() != 'PENDING') {
			throw new SonicCommandFailedException($queryMessage, $response);
		}
		$searchResult = $this->readResponse();
		if($searchResult->getVerb() != 'EVENT' && $searchResult->getArgument(0) != 'QUERY') {
			throw new SonicCommandFailedException($queryMessage, $searchResult);
		}
		return $searchResult->asArray(2);
	}
	
	/**
	 * Request word suggestion from sonic, for the given string.
	 * @param collection string Collection within Sonic to search for suggestions in.
	 * @param bucket string Bucket within the collection to search for suggestions in.
	 * @param word Beginning of the word to request completions for.
	 * @param limit int Optional limit to the amount of returned suggestions.
	 * @throws SonicConnectionLostException If the connection to Sonic has been lost in the meantime.
	 * @throws SonicCommandFailedException If execution of the command failed for which-ever reason.
	 */
	public function suggest(string $collection, string $bucket, string $word, int $limit = null) : array {
		$word = SonicMessage::sanitizeValue($word);
		$suggestMessage = new SonicMessage(['SUGGEST', $collection, $bucket,
			SonicMessage::quoted($word)
		]);
		if($limit != null) { $suggestMessage->setArgumentKeyVal(3, 'LIMIT', $limit); }
		$response = $this->sendAndAwaitResponse($suggestMessage);
		if($response->getVerb() != 'PENDING') {
			throw new SonicCommandFailedException($suggestMessage, $response);
		}
		$suggestResult = $this->readResponse();
		if($suggestResult->getVerb() != 'EVENT' && $suggestResult->getArgument(0) != 'SUGGEST') {
			throw new SonicCommandFailedException($suggestMessage, $suggestResult);
		}
		return $suggestResult->asArray(2);
	}
	
};

?>
