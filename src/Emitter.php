<?php

namespace SocketIO;

define('EVENT', 2);
define('BINARY_EVENT', 5);

class Emitter {
  const UID = "4N8LaD";
  const DEFAULT_KEY = 'socket.io';
  const DEFAULT_NSP = '/';

  public function __construct($redis = FALSE, $opts = array()) {
    if (is_array($redis)) {
      $opts = $redis;
      $redis = FALSE;
    }

    // Apply default arguments
    $opts = array_merge(array('host' => 'localhost', 'port' => 6379), $opts);

    if (!$redis) {
      // Default to phpredis
      if (extension_loaded('redis')) {
        if (!isset($opts['socket']) && !isset($opts['host'])) throw new \Exception('Host should be provided when not providing a redis instance');
        if (!isset($opts['socket']) && !isset($opts['port'])) throw new \Exception('Port should be provided when not providing a redis instance');

        $redis = new \Redis();
        if (isset($opts['socket'])) {
          $redis->connect($opts['socket']);
        } else {
          $redis->connect($opts['host'], $opts['port']);
        }
      } else {
        $redis = new \TinyRedisClient($opts['host'].':'.$opts['port']);
      }
    }

    if (!is_callable(array($redis, 'publish'))) {
      throw new \Exception('The Redis client provided is invalid. The client needs to implement the publish method. Try using the default client.');
    }

    $this->redis = $redis;
    $this->key = (isset($opts['key']) ? $opts['key'] : self::DEFAULT_KEY);

    $this->_rooms = array();
    $this->setDefaultFlags();
    $this->_exceptRooms = array();
    $this->_packer = new \MessagePack\Packer();
  }

  /*
   * Flags
   */

  public function __get($flag) {
    $this->_flags[$flag] = TRUE;
    return $this;
  }

  private function readFlag($flag) {
    return isset($this->_flags[$flag]) ? $this->_flags[$flag] : false;
  }

  private function setDefaultFlags() {
    $this->_flags = array('nsp' => self::DEFAULT_NSP);
  }

  /*
   * Broadcasting
   */

  public function in($room) {
    if (!in_array($room, $this->_rooms)) {
      $this->_rooms[] = $room;
    }

    return $this;
  }

  public function except($room) {
    if (!in_array($room, $this->_exceptRooms)) {
      $this->_exceptRooms[] = $room;
    }

    return $this;
  }

  // Alias for in
  public function to($room) {
    return $this->in($room);
  }

  /*
   * Namespace
   */

  public function of($nsp) {
    $this->_flags['nsp'] = $nsp;

    return $this;
  }

  /*
   * Emitting
   */

  public function emit() {
    $args = func_get_args();
    $packet = array();

    $packet['type'] = EVENT;
    // handle binary wrapper args
    for ($i = 0; $i < count($args); $i++) {
      $arg = $args[$i];
      if ($arg instanceof Binary) {
        $args[$i] = strval($arg);
        $this->binary;
      }
    }

    if ($this->readFlag('binary')) $packet['type'] = BINARY_EVENT;

    $packet['data'] = $args;

    // set namespace
    $nsp = $this->_flags['nsp'];
    $packet['nsp'] = $nsp;
    unset($this->_flags['nsp']);

    // publish
    $opts = array(
            'rooms' => $this->_rooms,
            'flags' => $this->_flags,
            'except' => $this->_exceptRooms,
    );

    $packed = $this->_packer->pack(array(self::UID, $packet, $opts));

    // hack buffer extensions for msgpack with binary
    if ($packet['type'] == BINARY_EVENT) {
      $packed = str_replace(pack('c', 0xda), pack('c', 0xd8), $packed);
      $packed = str_replace(pack('c', 0xdb), pack('c', 0xd9), $packed);
    }

    $prefix = $this->key.'#'.$nsp.'#';
    $this->redis->publish($prefix, $packed);

    // reset state
    $this->_rooms = array();
    $this->_exceptRooms = array();
    $this->setDefaultFlags();

    return $this;
  }
}
