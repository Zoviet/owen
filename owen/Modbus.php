<?php
namespace owen;
require 'vendor/autoload.php';	
   /**
   * Modbus
   *
   * Расширение класса ModbusMaster без закрытия сокетов и без единого протоколирования всего лога
   * Используется только те функции чтения и записи, которые использованы для продукции ОВЕН 
   *
   */

class Modbus extends \ModbusMaster {

	public function __construct($host, $protocol){
		$this->socket_protocol = $protocol;
		$this->host = $host;
		$this->connect();
	}


  public function close()
  {
	  if (get_resource_type($this->sock)=='Socket') {
		socket_shutdown($this->sock, 2);
		$this->disconnect();	
	  }
	  
  } 
  
   /**
   * send
   *
   * Send the packet via Modbus
   *
   * @param string $packet
   */
  private function send($packet){
    socket_write($this->sock, $packet, strlen($packet));  
    $this->status .= "Send\n";
  }
  
  /**
   * rec
   *
   * Receive data from the socket
   *
   * @return bool
   */
  private function rec(){
    socket_set_nonblock($this->sock);
    $readsocks[] = $this->sock;     
    $writesocks = NULL;
    $exceptsocks = NULL;
    $rec = "";
    $lastAccess = time();
    while (socket_select($readsocks, 
            $writesocks, 
            $exceptsocks,
            0, 
            300000) !== FALSE) {
            $this->status .= "Wait data ... \n";
        if (in_array($this->sock, $readsocks)) {
            while (@socket_recv($this->sock, $rec, 2000, 0)) {
                $this->status .= "Data received\n";
                return $rec;
            }
            $lastAccess = time();
        } else {             
            if (time()-$lastAccess >= $this->timeout_sec) {
                throw new \Exception( "Watchdog time expired [ " .
                  $this->timeout_sec . " sec]!!! Connection to " . 
                  $this->host . " is not established.");
            }
        }
        $readsocks[] = $this->sock;
    }
  } 
  
  /**
   * responseCode
   *
   * Check the Modbus response code
   *
   * @param string $packet
   * @return bool
   */
  private function responseCode($packet){    
    if((ord($packet[7]) & 0x80) > 0) {
      // failure code
      $failure_code = ord($packet[8]);
      // failure code strings
      $failures = array(
        0x01 => "ILLEGAL FUNCTION",
        0x02 => "ILLEGAL DATA ADDRESS",
        0x03 => "ILLEGAL DATA VALUE",
        0x04 => "SLAVE DEVICE FAILURE",
        0x05 => "ACKNOWLEDGE",
        0x06 => "SLAVE DEVICE BUSY",
        0x08 => "MEMORY PARITY ERROR",
        0x0A => "GATEWAY PATH UNAVAILABLE",
        0x0B => "GATEWAY TARGET DEVICE FAILED TO RESPOND");
      // get failure string
      if(key_exists($failure_code, $failures)) {
        $failure_str = $failures[$failure_code];
      } else {
        $failure_str = "UNDEFINED FAILURE CODE";
      }
      // exception response
      throw new \Exception("Modbus response error code: $failure_code ($failure_str)");
    } else {
      $this->status .= "Modbus response error code: NOERROR\n";
      return true;
    }    
  }
  
  /**
   * readCoilsParser
   * 
   * FC 1 response parser
   * 
   * @param type $packet
   * @param type $quantity
   * @return type 
   */
  private function readCoilsParser($packet, $quantity){    
    $data = array();
    // check Response code
    $this->responseCode($packet);
    // get data from stream
    for($i=0;$i<ord($packet[8]);$i++){
      $data[$i] = ord($packet[9+$i]);
    }    
    // get bool values to array
    $data_bolean_array = array();
    $di = 0;
    foreach($data as $value){
      for($i=0;$i<8;$i++){
        if($di == $quantity) continue;
        // get boolean value 
        $v = ($value >> $i) & 0x01;
        // build boolean array
        if($v == 0){
          $data_bolean_array[] = FALSE;
        } else {
          $data_bolean_array[] = TRUE;
        }
        $di++;
      }
    }
    return $data_bolean_array;
  }
  
   /**
   * writeMultipleCoilsParser
   *
   * FC15 response parser
   *
   * @param string $packet
   * @return bool
   */
  private function writeMultipleCoilsParser($packet){
    $this->responseCode($packet);
    return true;
  }
  
   /**
   * readMultipleRegistersPacketBuilder
   *
   * Packet FC 3 builder - read multiple registers
   *
   * @param int $unitId
   * @param int $reference
   * @param int $quantity
   * @return string
   */
  private function readMultipleRegistersPacketBuilder($unitId, $reference, $quantity){
    $dataLen = 0;
    // build data section
    $buffer1 = "";
    // build body
    $buffer2 = "";
    $buffer2 .= \iecType::iecBYTE(3);             // FC 3 = 3(0x03)
    // build body - read section    
    $buffer2 .= \iecType::iecINT($reference);  // refnumber = 12288      
    $buffer2 .= \iecType::iecINT($quantity);       // quantity
    $dataLen += 5;
    // build header
    $buffer3 = '';
    $buffer3 .= \iecType::iecINT(rand(0,65000));   // transaction ID
    $buffer3 .= \iecType::iecINT(0);               // protocol ID
    $buffer3 .= \iecType::iecINT($dataLen + 1);    // lenght
    $buffer3 .= \iecType::iecBYTE($unitId);        //unit ID
    // return packet string
    return $buffer3. $buffer2. $buffer1;
  }
  
    /**
   * writeMultipleRegisterParser
   *
   * FC16 response parser
   *
   * @param string $packet
   * @return bool
   */
  private function writeMultipleRegisterParser($packet){
    $this->responseCode($packet);
    return true;
  }
  
    /**
   * connect
   *
   * Connect the socket
   *
   * @return bool
   */
  protected function connect(){
    // Create a protocol specific socket 
    if ($this->socket_protocol == "TCP"){ 
        // TCP socket
        $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);      
    } elseif ($this->socket_protocol == "UDP"){
        // UDP socket
        $this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    } else {
        throw new \Exception("Unknown socket protocol, should be 'TCP' or 'UDP'");
    }
    // Bind the client socket to a specific local port
    if (strlen($this->client)>0){
        $result = socket_bind($this->sock, $this->client, $this->client_port);
        if ($result === false) {
            throw new \Exception("socket_bind() failed.</br>Reason: ($result)".
                socket_strerror(socket_last_error($this->sock)));
        } else {
            $this->status .= "Bound\n";
        }
    }
    // Socket settings
    socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0));
    // Connect the socket
    $result = @socket_connect($this->sock, $this->host, $this->port);
    if ($result === false) {
        throw new \Exception("socket_connect() failed.</br>Reason: ($result)".
            socket_strerror(socket_last_error($this->sock)));
    } else {
        $this->status .= "Connected\n";
        return true;        
    }    
  }

  /**
   * disconnect
   *
   * Disconnect the socket
   */
  protected function disconnect(){    
    socket_close($this->sock);
    $this->status .= "Disconnected\n";
  }
 
  /**
   * readCoils
   * 
   * Modbus function FC 1(0x01) - Read Coils
   * 
   * Reads {@link $quantity} of Coils (boolean) from reference 
   * {@link $reference} of a memory of a Modbus device given by 
   * {@link $unitId}.
   * 
   * @param type $unitId
   * @param type $reference
   * @param type $quantity 
   */
  public function readCoils($unitId, $reference, $quantity){
    $this->status = "readCoils: START\n"; 
    $packet = $this->readCoilsPacketBuilder($unitId, $reference, $quantity);
    $this->status .= $this->printPacket($packet);    
    $this->send($packet);
    // receive response
    $rpacket = $this->rec();
    $this->status .= $this->printPacket($rpacket);    
    // parse packet    
    $receivedData = $this->readCoilsParser($rpacket, $quantity);
    $this->status .= "readCoils: DONE\n";
    // return
    return $receivedData;
  }  
  
    /**
   * readCoilsPacketBuilder
   * 
   * FC1 packet builder - read coils
   * 
   * @param type $unitId
   * @param type $reference
   * @param type $quantity
   * @return type 
   */
  private function readCoilsPacketBuilder($unitId, $reference, $quantity){
    $dataLen = 0;
    // build data section
    $buffer1 = "";
    // build body
    $buffer2 = "";
    $buffer2 .= \iecType::iecBYTE(1);              // FC 1 = 1(0x01)
    // build body - read section    
    $buffer2 .= \iecType::iecINT($reference);      // refnumber = 12288      
    $buffer2 .= \iecType::iecINT($quantity);       // quantity
    $dataLen += 5;
    // build header
    $buffer3 = '';
    $buffer3 .= \iecType::iecINT(rand(0,65000));   // transaction ID
    $buffer3 .= \iecType::iecINT(0);               // protocol ID
    $buffer3 .= \iecType::iecINT($dataLen + 1);    // lenght
    $buffer3 .= \iecType::iecBYTE($unitId);        //unit ID
    // return packet string
    return $buffer3. $buffer2. $buffer1;
  }
  
  /**
   * readMultipleRegisters
   *
   * Modbus function FC 3(0x03) - Read Multiple Registers.
   * 
   * This function reads {@link $quantity} of Words (2 bytes) from reference 
   * {@link $referenceRead} of a memory of a Modbus device given by 
   * {@link $unitId}.
   *    
   *
   * @param int $unitId usually ID of Modbus device 
   * @param int $reference Reference in the device memory to read data (e.g. in device WAGO 750-841, memory MW0 starts at address 12288).
   * @param int $quantity Amounth of the data to be read from device.
   * @return false|Array Success flag or array of received data.
   */
  public function readMultipleRegisters($unitId, $reference, $quantity){
    $this->status = "readMultipleRegisters: START\n";   
    $packet = $this->readMultipleRegistersPacketBuilder($unitId, $reference, $quantity);
    $this->status .= $this->printPacket($packet);    
    $this->send($packet);
    // receive response
    $rpacket = $this->rec();
    $this->status .= $this->printPacket($rpacket);    
    // parse packet    
    $receivedData = $this->readMultipleRegistersParser($rpacket);
    $this->status .= "readMultipleRegisters: DONE\n";
    // return
    return $receivedData;
  }
  
   
  /**
   * writeMultipleCoils
   * 
   * Modbus function FC15(0x0F) - Write Multiple Coils
   *
   * This function writes {@link $data} array at {@link $reference} position of 
   * memory of a Modbus device given by {@link $unitId}. 
   * 
   * @param type $unitId
   * @param type $reference
   * @param type $data
   * @return type 
   */
  public function writeMultipleCoils($unitId, $reference, $data){
    $this->status = "writeMultipleCoils: START\n";
    $packet = $this->writeMultipleCoilsPacketBuilder($unitId, $reference, $data);
    $this->status .= $this->printPacket($packet);    
    $this->send($packet);
    // receive response
    $rpacket = $this->rec();
    $this->status .= $this->printPacket($rpacket);    
    // parse packet
    $this->writeMultipleCoilsParser($rpacket);
    $this->status .= "writeMultipleCoils: DONE\n";
    return true;
  }
  
   /**
   * writeMultipleCoilsPacketBuilder
   *
   * Packet builder FC15 - Write multiple coils
   *
   * @param int $unitId
   * @param int $reference
   * @param array $data
   * @return string
   */
  private function writeMultipleCoilsPacketBuilder($unitId, $reference, $data){
    $dataLen = 0;    
    // build bool stream to the WORD array
    $data_word_stream = array();
    $data_word = 0;
    $shift = 0;    
    for($i=0;$i<count($data);$i++) {
      if((($i % 8) == 0) && ($i > 0)) {
        $data_word_stream[] = $data_word;
        $shift = 0;
        $data_word = 0;
        $data_word |= (0x01 && $data[$i]) << $shift;
        $shift++;
      }
      else {
        $data_word |= (0x01 && $data[$i]) << $shift;
        $shift++;
      }
    }
    $data_word_stream[] = $data_word;
    // show binary stream to status string
    foreach($data_word_stream as $d){
        $this->status .= sprintf("byte=b%08b\n", $d);
    }    
    // build data section
    $buffer1 = "";
    foreach($data_word_stream as $key=>$dataitem) {
        $buffer1 .= \iecType::iecBYTE($dataitem);   // register values x
        $dataLen += 1;
    }
    // build body
    $buffer2 = "";
    $buffer2 .= \iecType::iecBYTE(15);             // FC 15 = 15(0x0f)
    $buffer2 .= \iecType::iecINT($reference);      // refnumber = 12288      
    $buffer2 .= \iecType::iecINT(count($data));      // bit count      
    $buffer2 .= \iecType::iecBYTE((count($data)+7)/8);       // byte count
    $dataLen += 6;
    // build header
    $buffer3 = '';
    $buffer3 .= \iecType::iecINT(rand(0,65000));   // transaction ID    
    $buffer3 .= \iecType::iecINT(0);               // protocol ID    
    $buffer3 .= \iecType::iecINT($dataLen + 1);    // lenght    
    $buffer3 .= \iecType::iecBYTE($unitId);        // unit ID    
     
    // return packet string
    return $buffer3. $buffer2. $buffer1;
  }
  
  /**
   * writeMultipleRegister
   *
   * Modbus function FC16(0x10) - Write Multiple Register.
   *
   * This function writes {@link $data} array at {@link $reference} position of 
   * memory of a Modbus device given by {@link $unitId}.
   *
   *
   * @param int $unitId usually ID of Modbus device 
   * @param int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address 12288)
   * @param array $data Array of values to be written.
   * @param array $dataTypes Array of types of values to be written. The array should consists of string "INT", "DINT" and "REAL".    
   * @return bool Success flag
   */       
  public function writeMultipleRegister($unitId, $reference, $data, $dataTypes){
    $this->status = "writeMultipleRegister: START\n";  
    $packet = $this->writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes);
    $this->status .= $this->printPacket($packet);    
    $this->send($packet);
    // receive response
    $rpacket = $this->rec();
    $this->status .= $this->printPacket($rpacket);    
    // parse packet
    $this->writeMultipleRegisterParser($rpacket);
    $this->status .= "writeMultipleRegister: DONE\n";
    return true;
  }
  
    /**
   * writeMultipleRegisterPacketBuilder
   *
   * Packet builder FC16 - WRITE multiple register
   *     e.g.: 4dd90000000d0010300000030603e807d00bb8
   *
   * @param int $unitId
   * @param int $reference
   * @param array $data
   * @param array $dataTypes
   * @return string
   */
  private function writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes){
    $dataLen = 0;
    // build data section
    $buffer1 = "";
    foreach($data as $key=>$dataitem) {
      if($dataTypes[$key]=="INT"){
        $buffer1 .= \iecType::iecINT($dataitem);   // register values x
        $dataLen += 2;
      }
      elseif($dataTypes[$key]=="DINT"){
        $buffer1 .= \iecType::iecDINT($dataitem, $this->endianness);   // register values x
        $dataLen += 4;
      }
      elseif($dataTypes[$key]=="REAL") {
        $buffer1 .= \iecType::iecREAL($dataitem, $this->endianness);   // register values x
        $dataLen += 4;
      }       
      else{
        $buffer1 .= \iecType::iecINT($dataitem);   // register values x
        $dataLen += 2;
      }
    }
    // build body
    $buffer2 = "";
    $buffer2 .= \iecType::iecBYTE(16);             // FC 16 = 16(0x10)
    $buffer2 .= \iecType::iecINT($reference);      // refnumber = 12288      
    $buffer2 .= \iecType::iecINT($dataLen/2);        // word count      
    $buffer2 .= \iecType::iecBYTE($dataLen);     // byte count
    $dataLen += 6;
    // build header
    $buffer3 = '';
    $buffer3 .= \iecType::iecINT(rand(0,65000));   // transaction ID    
    $buffer3 .= \iecType::iecINT(0);               // protocol ID    
    $buffer3 .= \iecType::iecINT($dataLen + 1);    // lenght    
    $buffer3 .= \iecType::iecBYTE($unitId);        //unit ID    
    
    // return packet string
    return $buffer3. $buffer2. $buffer1;
  }
  
   /**
   * byte2hex
   *
   * Parse data and get it to the Hex form
   *
   * @param char $value
   * @return string
   */
  private function byte2hex($value){
    $h = dechex(($value >> 4) & 0x0F);
    $l = dechex($value & 0x0F);
    return "$h$l";
  }
  
  /**
   * readMultipleRegistersParser
   *
   * FC 3 response parser
   *
   * @param string $packet
   * @return array
   */
  private function readMultipleRegistersParser($packet){    
    $data = array();
    // check Response code
    $this->responseCode($packet);
    // get data
    for($i=0;$i<ord($packet[8]);$i++){
      $data[$i] = ord($packet[9+$i]);
    }    
    return $data;
  }

  /**
   * printPacket
   *
   * Print a packet in the hex form
   *
   * @param string $packet
   * @return string
   */
  private function printPacket($packet){
    $str = "";   
    $str .= "Packet: "; 
    for($i=0;$i<strlen($packet);$i++){
      $str .= $this->byte2hex(ord($packet[$i]));
    }
    $str .= "\n";
    return $str;
  }

}
