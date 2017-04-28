# push_server

simple php websockets server

----------------------------------------------------------------------

features/limitations:
- two file (server.php and shared_utils.php)
- supports RFC6455 (version 13) only
- supports text data frames (doesn't support binary data frames)
- supports close, ping (receive only) and pong (send only) control frames
- supports receiving fragmented frames
- doesn't send fragmented frames
- doesn't support extensions
- no dependencies other than php (tested with version 5.6)
- uses procedural programming style
- no framework cruft
- event handlers for including application-specific code
- named pipe to receive data from other processes (eg ajax requests)

----------------------------------------------------------------------

## installing

- download server.php to your host
- create "server_shared.conf" file and update SETTINGS_FILENAME constant on line 11 to reflect location of file

example settings file (all keys beginning with "WEBSOCKET_" are required):
````
WEBSOCKET_LISTENING_ADDRESS=localhost
WEBSOCKET_LISTENING_PORT=50000
WEBSOCKET_SELECT_TIMEOUT=200000
WEBSOCKET_SERVER_HEADER=SimplePHPWS
WEBSOCKET_XHR_PIPE_FILE=/var/include/vhosts/default/inc/data/ws_notify
WEBSOCKET_EVENTS_INCLUDE_FILE=/var/include/vhosts/default/inc/push_server_events.php
WEBSOCKET_LOG_PATH=/var/include/vhosts/default/inc/ws_logs/
DB_HOST=localhost
DB_SCHEMA=myapp
DB_USER=www
DB_PASSWORD=***
````

- you can add extra settings in this file that may be shared between the main application and the push server, such as database connectivity parameters, depending on the requirements of your application-specific event handlers
- create the log path (specified by WEBSOCKET_LOG_PATH)
- create push server events file corresponding to WEBSOCKET_EVENTS_INCLUDE_FILE that contains the required and (if applicable) optional event handler functions.
- change the script location on line 8 of push_server.service to suit
- as root user (change source path to suit):
````
cp /var/include/vhosts/default/inc/push_server/push_server.service /etc/systemd/system
systemctl enable push_server.service
systemctl daemon-reload
````
- check status of server after reboot with (as root):
````
systemctl status push_server.service -l
````

- you can start/stop push_server.service using systemctl (as root)
example:
````
systemctl start push_server.service
````

----------------------------------------------------------------------

## running

to run ws server: php server.php (or use systemd service file)

----------------------------------------------------------------------

## event handlers

these event handler are the interfaces by which your application interacts with the websocket server

there are function arguments that are shared amongst more than one event handler

----------------------------------------------------------------------

### &$server shared argument

reference to the stream socket server handle returned from the [stream_socket_server](http://php.net/manual/en/function.stream-socket-server.php) php function

### &$sockets shared argument

reference to array of socket handles, including the server socket (first)

client sockets are handles returned from the [stream_socket_accept](http://php.net/manual/en/function.stream-socket-accept.php) php function

### &$connection shared argument

reference to an array containing data for each client connection

example structure:
````
array(5) {
  ["peer_name"]=>
  string(15) "127.0.0.1:37814"
  ["state"]=>
  string(4) "OPEN"
  ["buffer"]=>
  array(0) {
  }
}
````

the connection array can also contain other application-specific data that is managed by and passed between event handlers

### &$connections shared argument

### &$frame shared argument

----------------------------------------------------------------------

### ws_server_authenticate event handler (required)

syntax:
````
function ws_server_authenticate(&$connection,$cookies,$user_agent,$remote_address)
````

### ws_server_initialize event handler (optional)

syntax:
````
function ws_server_initialize()
````

### ws_server_started event handler (optional)

syntax:
````
function ws_server_started(&$server,&$sockets,&$connections)
````

### ws_server_fifo event handler (optional)

syntax:
````
function ws_server_fifo(&$server,&$sockets,&$connections,&$fifo_data)
````

### ws_server_loop event handler (optional)

syntax:
````
function ws_server_loop(&$server,&$sockets,&$connections)
````

### ws_server_open event handler (optional)

syntax:
````
function ws_server_open(&$connections,&$connection,$client_key)
````

### ws_server_before_close event handler (optional)

syntax:
````
function ws_server_before_close(&$connections,&$connection)
````

### ws_server_after_close event handler (optional)

syntax:
````
function ws_server_after_close(&$connections)
````

### ws_server_read event handler (optional)

syntax:
````
function ws_server_read(&$connections,&$connection,$data)
````

### ws_server_text event handler (optional)

syntax:
````
function ws_server_text(&$connections,&$connection,$msg)
````

### ws_server_shutdown event handler (optional)

syntax:
````
function ws_server_shutdown(&$server,&$sockets,&$connections)
````

### ws_server_finalize event handler (optional)

syntax:
````
function ws_server_finalize()
````

----------------------------------------------------------------------

# autobahn/testsuite

used to test this server

refer to http://autobahn.ws/testsuite

## installing on debian jessie

as root user:
````
apt-get install python-setuptools
apt-get install python-dev libxml2-dev libxslt-dev
apt-get install python-twisted
apt-get install python-pip
pip install cffi
pip install traceback2
pip install attrs
pip install autobahntestsuite
````

## running tests

as non-root user (from repo directory):
````
wstest -m fuzzingclient -s fuzzingclient.json
````
