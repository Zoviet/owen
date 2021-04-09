<?php
namespace owen;
require 'vendor/autoload.php';	

   /**
   * Connect
   *
   * Реализация функций чтения/записи через Modbus TCP для контроллеров ОВЕН с учетом их особенностей
   * Все функции принимают параметр connect - массив [адрес, байт, функция чтения/записи, тип получаемой переменной, описание, название внутренней переменной, передаваемое значение]
   *
   */
class Connect  {				
	
	public static $errors; //ошибки 
	public static $messages; //сообщения 
	protected static $errors_text;
	protected static $messages_text;
	public $endianess; //порядок байт в слове
	
	public function __construct($type=NULL,$data=NULL)
	{
		self::$errors = NULL;
		self::$messages = NULL;
		self::$errors_text = Config::get('connect_errors');
		self::$messages_text = Config::get('connect_messages');
		$this->config = Config::get('connect'); //получили настройки соединения	
		$this->addrs = Config::connect_addrs();//адреса связи с внешним устройством	
		$this->modbus = new Modbus($this->config->IP, 'TCP'); //расширение класса ModbusMaster без закрытия сокетов			
		if ($this->config->Endianess=='BIG_ENDIAN') $this->endianess=1; else $this->endianess=0; //выбираем порядок байт. биг - обратный		
	}		

   /**
   * Закрытие соединения
   *
   */	
	public function close() 
	{	
		$this->modbus->close();				
	}

   /**
   * Запись coils в виде байта
   *
   */	
	public function WriteCoils($connect,$data)
	{
		$data = ($data == TRUE) ? 1 : 0;
		$senddata = array_fill(0,8,0);
		$senddata[$connect[1]] = $data;		
		$senddata = bindec(implode('',$senddata));
		$connect[1] = 1; //1 байт
		$connect[3] = 'WORD';		
		$this->WriteRegister($connect,$senddata);
	}	

	/**
   * Чтение бита по коду 0х01. 
   * Получаем массив значений байта по адресу. 
   * Если не указывать смещение (номер бита), то весь массив битов байта получаем.
   *
   */	
	public function ReadCoil($connect)
	{		
		try {				
			$data = $this->modbus->readMultipleRegisters($this->config->UnitID, $connect[0], 1);
			$data=array_reverse($data);
			$data_bolean_array = array();
			$di = 0;
			foreach($data as $value){
				for($i=0;$i<8;$i++){
					if($di == 16) continue;
					$v = ($value >> $i) & 0x01;
					if($v == 0){
						$data_bolean_array[] = FALSE;
					} else {
						$data_bolean_array[] = TRUE;
					}
				$di++;
				}
			}	
			self::$messages[] = nl2br($this->modbus->status);
		}		
		catch (Exception $e) {			
			self::$errors[] = $e;    
			exit;
		}		
		$data = (isset($connect[1]) and is_numeric($connect[1])) ? $data_bolean_array[$connect[1]] : $data_bolean_array;
		return $data;
	}
		
	/**
	* Запись битов по коду 0х05(0f!!!) 
	* в виде массива array(TRUE,FALSE,TRUE), где каждый элемент - один бит от начального адреса
	*
	*/
	public function WriteCoil($connect, bool $data=false)
	{	
		$senddata=array_fill(0,16,false);
		$senddata[$connect[1]] = $data;
		
		if(!empty($data) and !is_array($data)) $senddata[0]= $data;	
		self::$messages[]= self::$messages_text[5].' : '.$data;		
		try {
			$this->modbus-> writeMultipleCoils($this->config->UnitID,$connect[0], $senddata);
			self::$messages[] = nl2br($this->modbus->status);
		}
		catch (Exception $e) {			
			self::$errors[] = $e;    
			exit;
		}			
		return TRUE;
	}
	
	/**
	* Чтение регистра по коду 0х03
	*
	*/
	public function ReadRegister($connect)
	{
		try {
			$data = $this->modbus->readMultipleRegisters($this->config->UnitID, $connect[0], $connect[1]);
			self::$messages[] = nl2br($this->modbus->status);
		}
		catch (Exception $e) {
			self::$errors[] = $e;    
			exit;
		}			
		switch ($connect[3]) { //выбор типа получаемых данных
			case 'WORD': // двухбайтовая переменная
				$data = \PhpType::bytes2signedInt($data,$this->endianess);
			break;
			case 'DWORD': // четырехбайтовая переменная
				$data = \PhpType::bytes2signedInt($data,$this->endianess); // учитываем порядок байт
			break;
			case 'REAL': // четырехбайтовая переменная
				$data = \PhpType::bytes2float($data,$this->endianess); // учитываем порядок байт
			break;			
			default:
				self::$errors[] = self::$errors_text[2];
				exit;
			break;
		}
		return $data;
	}

	/**
	* Чтение массива данных
	*
	*/
	public function ReadArray($connect)
	{
		$array = array();
		$i = 1;
		try {
			$data = $this->modbus->readMultipleRegisters($this->config->UnitID, $connect[0], $connect[1]);
			self::$messages[] = nl2br($this->modbus->status);
		}
		catch (Exception $e) {
			self::$errors[] = $e;    
			exit;
		}				
		$values = array_chunk($data, 4); //преобразование в байты: каждый байт 2 регистра модбаса или 4 регистра контроллера

		foreach($values as $bytes) {	
			$i++;			
			$array[floor($i/2)][] = \PhpType::bytes2signedInt($bytes,$this->endianess); 			
		}			
		return array_values($array);
	}
	

	/**
	* Запись регистра по коду 0х10
	*
	*/
	public function WriteRegister($connect,$data)
	{
		$senddata = array();
		$types = array("WORD", "INT", "DINT", "REAL"); //допустимые типы данных		
		if (in_array($connect[3],$types)) {
			$senddata[0] = $data;
			$dataTypes[0] = $connect[3];
		} else {
			self::$errors[] = self::$errors_text[3];
			exit;
		}
		try {
			$this->modbus->writeMultipleRegister($this->config->UnitID, $connect[0], $senddata, $dataTypes);
			self::$messages[] = nl2br($this->modbus->status);
		}
		catch (Exception $e) {
			self::$errors[] = self::$errors_text[1];    
			exit;
		}
		return TRUE;
	}
	
	/**
	* Отправка данных
	*
	*/
	public function push($connect,$data) //отправка данных
	{
		$type = $connect[2];
		switch ($type) {
			case 'ReadCoil':
				$type = 'WriteCoil';
			break;
			case 'ReadRegister':				
				$type = 'WriteRegister';
				if ($connect[3]=='DWORD') $connect[3] = 'DINT';
			break;			
		}					
		return $this->$type($connect,$data);
	}
	
	/**
	* Получение данных
	*
	*/
	public function pull($connect) 
	{
		$type = $connect[2];				
		return $this->$type($connect);
	}
	
	/**
	* Тест адресов на коннект
	*
	*/
	public function test($connect) 
	{	
		self::$messages = array();
		$type = $connect[2];			
		self::$messages[] = self::$messages_text[0];
		self::$messages[] = $connect[4];
		if (!empty($connect[5])) self::$messages[] = $connect[5];
		self::$messages[] = self::$messages_text[1].' : '.$connect[0];
		self::$messages[] = self::$messages_text[2].' : '.$connect[1];	
		try {		
			$return = (!empty($connect[6])) ? $this->$type($connect,$connect[6]) : $this->$type($connect);	
			if (is_array($return)) {$return = 'Array';}
			self::$messages[] = self::$messages_text[6].' : '.$return;
		} catch (Exception $e) {
			self::$errors[] = $e;   
			exit;
		}
	}
	
	
}
