<?php

#####################################################################################################

# for help with ssl server: http://php.net/manual/en/function.stream-socket-server.php#118419

#####################################################################################################

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("UTC");

if (isset($argv[1])==True)
{
  switch ($argv[1])
  {
    case "test":
      run_all_tests();
      return;
  }
}

$sockets=array();
$connections=array();
$server=stream_socket_server("tcp://127.0.0.1:50000",$err_no,$err_msg);
if ($server===False)
{
  show_message("could not bind to socket: ".$err_msg,True);
  return;
}
stream_set_blocking($server,0);
$sockets[]=$server;
while (True)
{
  $read=array(STDIN);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,100000);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets(STDIN));
      if ($data=="q")
      {
        break;
      }
    }
  }
  $read=$sockets;
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,100000);
  if ($change_count===False)
  {
    show_message("stream_select failed",True);
    break;
  }
  if ($change_count<1)
  {
    continue;
  }
  foreach ($read as $read_key => $read_socket)
  {
    if ($read[$read_key]===$server)
    {
      $client=stream_socket_accept($server);
      stream_set_blocking($client,0);
      $sockets[]=$client;
      $client_key=array_search($client,$sockets,True);
      $new_connection=array();
      $new_connection["mode"]="http";
      $connections[$client_key]=$new_connection;
      show_message("client connected");
    }
    else
    {
      $client_key=array_search($read[$read_key],$sockets,True);
      $data="";
      do
      {
        $buffer=fread($sockets[$client_key],8);
        if (strlen($buffer)===False)
        {
          show_message("read error",True);
          close_client($client_key);
          continue 2;
        }
        $data.=$buffer;
      }
      while (strlen($buffer)>0);
      if (strlen($data)==0)
      {
        show_message("client terminated connection",True);
        close_client($client_key);
        continue;
      }
      on_msg($client_key,$data);
    }
  }
}
foreach ($sockets as $key => $socket)
{
  if ($sockets[$key]!==$server)
  {
    close_client($key);
  }
}

stream_socket_shutdown($server,STREAM_SHUT_RDWR);
fclose($server);

#####################################################################################################

function close_client($client_key)
{
  global $sockets;
  global $connections;
  stream_socket_shutdown($sockets[$client_key],STREAM_SHUT_RDWR);
  fclose($sockets[$client_key]);
  unset($sockets[$client_key]);
  unset($connections[$client_key]);
}

#####################################################################################################

function on_msg($client_key,$data)
{
  global $connections;
  show_message("from client:",True);
  if ($connections[$client_key]["mode"]=="http")
  {
    var_dump($data);
    $headers=extract_headers($data);
    $sec_websocket_key=get_header($headers,"Sec-WebSocket-Key");
    $sec_websocket_accept=base64_encode(sha1($sec_websocket_key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",True));
    $msg="HTTP/1.1 101 Switching Protocols".PHP_EOL;
    $msg.="Upgrade: websocket".PHP_EOL;
    $msg.="Connection: Upgrade".PHP_EOL;
    $msg.="Sec-WebSocket-Accept: ".$sec_websocket_accept.PHP_EOL;
    $msg.="Sec-WebSocket-Protocol: chat\r\n\r\n";
    show_message("to client:",True);
    var_dump($msg);
    $connections[$client_key]["mode"]="websocket";
    do_reply($client_key,$msg);
  }
  elseif ($connections[$client_key]["mode"]=="websocket")
  {
    $data=decode_frame($data);
    var_dump($data);
  }
}

#####################################################################################################

function decode_frame($data)
{
  # message = "hello" (5 chars)
  # message received is 11 chars
  # message consists of single frame
  # mask = 4 bytes
  # message + frame = 9 bytes
  # leaves 2 bytes for payload length and opcode etc
/*
bit legend: name(numbits=>testval[,meaning])
array(11) {
  [1]=>
  int(129) # fin(1=>1)+rsv1(1=>0)+rsv2(1=>0)+rsv3(1=>0)+opcode(4=>0001,text frame) => "10000001"
  [2]=>
  int(133) # mask(1=>1)+ payloadlength(7=>0000101,5 chars) => "10000101"
  [3]=>
  int(5) # mask byte 1
  [4]=>
  int(115) # mask byte 2
  [5]=>
  int(34) # mask byte 3
  [6]=>
  int(3) # mask byte 4
  [7]=>
  int(109) # masked payload byte 1
  [8]=>
  int(22) # masked payload byte 2
  [9]=>
  int(78) # masked payload byte 3
  [10]=>
  int(111) # masked payload byte 4
  [11]=>
  int(106) # masked payload byte 5
}
$unmasked_char[$i] = $masked_char[$i] xor $mask[$i % 4]
*/
  $chars=unpack("C*",$data);
  $mask=array_slice($chars,2,4);
  $mask=array_values($mask);
  $message=array_slice($chars,6,5);
  $message=array_values($message);
  for ($i=0;$i<5;$i++)
  {
    $message[$i]=$message[$i]^$mask[$i%4];
    $message[$i]=chr($message[$i]);
  }
  return implode("",$message);
}

#####################################################################################################

function do_reply($client_key,$msg)
{
  global $sockets;
  fwrite($sockets[$client_key],$msg);
}

#####################################################################################################

function show_message($msg,$star=False)
{
  $prefix="";
  if ($star==True)
  {
    $prefix="*** ";
  }
  echo $prefix.$msg.PHP_EOL;
}

#####################################################################################################

function extract_headers($response)
{
  $delim="\r\n\r\n";
  $i=strpos($response,$delim);
  if ($i===False)
  {
    return False;
  }
  $headers=substr($response,0,$i);
  return explode(PHP_EOL,$headers);
}

#####################################################################################################

function get_header($lines,$header)
{
  for ($i=0;$i<count($lines);$i++)
  {
    $line=trim($lines[$i]);
    $parts=explode(":",$line);
    if (count($parts)>=2)
    {
      $key=trim(array_shift($parts));
      $value=trim(implode(":",$parts));
      if (strtolower($key)==strtolower($header))
      {
        return $value;
      }
    }
  }
  return False;
}

#####################################################################################################

function run_all_tests()
{
  $client=stream_socket_client("tcp://127.0.0.1:50000",$err_no,$err_msg);
  if ($client===False)
  {
    show_message("error connecting: ".$err_msg,True);
    return;
  }
  $tests=array("hello","a b","a\na"," ");
  for ($i=0;$i<count($tests);$i++)
  {
    fwrite($client,$tests[$i]);
    $data=fread($client,1024);
    show_message("reply: ".$data);
    if ($data==$tests[$i])
    {
      show_message("[SUCCESS] reply: ".$data);
    }
    else
    {
      show_message("[FAIL] reply: ".$data);
    }
    sleep(1);
  }
  stream_socket_shutdown($client,STREAM_SHUT_RDWR);
  fclose($client);
}

#####################################################################################################
