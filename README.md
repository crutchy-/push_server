# push_server

simple php websockets server

----------------------------------------------------------------------

features/limitations:
- one file (server.php)
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
- named pipe for receiving data from webserver ajax requests

----------------------------------------------------------------------

## installing

- download server.php to your host
- change the PIPE_FILE constant to reflect a named pipe filename in a path that exists and has read/write permissions
- create a push server events file that contains the event handler functions as required by your application. individual event handlers are optional so if you only need (for example) the ws_server_text handler then just create a function for it and leave out the rest. see below for explanation for what each handler is for.

----------------------------------------------------------------------

## event handlers

each event handler may be optionally implemented by your application. they are the interfaces by which your application interacts with the websocket server

there are function arguments that are shared amongst more than one event handler

----------------------------------------------------------------------

### &$server shared argument

### &$sockets shared argument

### &$connection shared argument

### &$connections shared argument

### &$frame shared argument

----------------------------------------------------------------------

### ws_server_fifo event handler

syntax:
````
function ws_server_fifo(&$server,&$sockets,&$connections,&$fifo_data)
````

### ws_server_loop event handler

syntax:
````
function ws_server_loop(&$server,&$sockets,&$connections)
````

### ws_server_open event handler

syntax:
````
function ws_server_open(&$connection)
````

### ws_server_close event handler

syntax:
````
function ws_server_close(&$connection)
````

### ws_server_text event handler

syntax:
````
function ws_server_text(&$connection,&$frame)
````

### ws_server_ping event handler

syntax:
````
function ws_server_ping(&$connection,&$frame)
````

### ws_server_shutdown event handler

syntax:
````
function ws_server_shutdown(&$server,&$sockets,&$connections)
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

## using

as non-root user (from repo directory):
````
wstest -m fuzzingclient -s fuzzingclient.json
````
