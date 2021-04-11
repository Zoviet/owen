<?php
namespace owen;
require 'vendor/autoload.php';	

   /**
   * Connect
   *
   * Реализация функций чтения/записи через Modbus TCP для контроллеров ОВЕН с учетом их особенностей
   *
   */
class Connect  {			
	
	// сообщения об ошибках
	protected static $errors_text = [
		'Необходимо передать 0 (FALSE) или 1 (TRUE)',	
		'Ошибка при получении данных - запрошено количество байт не кратное 2 или 4',
		'Неправильно указан тип данных - поддерживается REAL, FLOAT, DWORD',
		'Тип передаваемых данных должен быть указан из набора WORD, INT, DINT, REAL',
		'Не удалось установить связь с ПЛК',
		'Не переданы данные для записи в устройство'	
	];
	
	// сообщения о работе класса
	protected static $messages_text = [
		'Тест соединения',
		'Начальный адрес',
		'Кол-во слов или номер бита',
		'Полученное значение',
		'Преобразованное значение',
		'Передаваемое значение',
		'Преобразованное значение'
	];
			
	// настройки соединения по умолчанию
	protected static $default_connect = [
		'IP'=>'127.0.0.1',  //IP-адрес PLC
		'Port'=>502, //Порт
		'UnitID'=>1, //ID устройства
		'Endianess'=>'LOW_ENDIAN', //Порядок байт по умолчанию: обратный порядок: 'BIG_ENDIAN'
	];
	
	//ошибки класса
	public static $errors = NULL; 
	
	//сообщения класса
	public static $messages = NULL; 
	
	//порядок байт в слове: BIG_ENDIAN -1, LOW - 0
	public $endianess; 
	
	//настройки соединения
	protected $connect;
	
	//адреса соединений
	public $vars;
	
	//тип переменных массива по умолчанию
	public $type = 'WORD';
	
	/**
	* Конструктор
	* 
	* Устанавливаются все базовые значения
	* @param array $connect Настройки соединения, см. структуру default_connect
	* @param array $vars Адреса соединения формат [адрес MODBUS или OWEN,смещение, тип операции, формат возвращаемых данных, данные]
	* @param bool $owen Использовать ли внутрненнюю адресацию ПЛК. Если да, то достаточно передавать массив vars в виде, но по очереди
	* @return void
	*/	
	public function __construct($connect=NULL,$vars=NULL,$owen=false)	{	
				
		$this->connect = (empty($connect) or !isset($connect['IP'])) ? self::$default_connect : array_merge(self::$default_connect,$connect);
		$this->modbus = new Modbus($this->connect['IP'], 'TCP'); //расширение класса ModbusMaster без закрытия сокетов		
		if ($this->connect['Endianess']=='BIG_ENDIAN') $this->endianess=1; else $this->endianess=0; //выбираем порядок байт. биг - обратный	
		$this->vars = ($owen===false) ? $vars : $this->varmap($vars);
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
			$data = $this->modbus->readMultipleRegisters($this->connect['UnitID'], $connect[0], 1);
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
			$this->modbus-> writeMultipleCoils($this->connect['UnitID'],$connect[0], $senddata);
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
			$data = $this->modbus->readMultipleRegisters($this->connect['UnitID'], $connect[0], $connect[1]);
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
			$data = $this->modbus->readMultipleRegisters($this->connect['UnitID'], $connect[0], $connect[1]);
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
			$this->modbus->writeMultipleRegister($this->connect['UnitID'], $connect[0], $senddata, $dataTypes);
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
	public function push($connect,$data=NULL) //отправка данных
	{
		if (is_string($connect)) {
			$connect = $this->vars[$connect];
			if (empty($data)) {
				$data = (isset($connect[4])) ? $connect[4] : self::$errors[] = self::$errors_text[5];
			}
		}
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
		if (is_string($connect)) {
			$connect = $this->vars[$connect];
		}
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
	
		/**
   * Перевод внутренней адресации OWEN в адресацию Modbus
   * 
   * Параметры должны передаваться по очереди (по росту адресов)
   * 
   * © InSAT Company 2009-2014 Modbus Universal MasterOPC сервер. Подключение контроллеров ОВЕН ПЛК1xx, Стр. 16
	* согласно правилам выравнивания, 4 байтовые переменные (которой является переменная Float)
	* могут располагаться только в адресах памяти кратных четырем.
   *
   */	
	protected function varmap($vars)
	{
		$return = array();		
		$now = 0; //регистр овен
		$next = 0;
		$mod = 1000;
		$bytes = ['WORD','BOOL'];
		$twobyte = ['REAL','DWORD'];
		foreach ($vars as $key=>$var) {			
			if (in_array($var[3],$bytes)) $byte=2;
			if (in_array($var[3],$twobyte)) $byte=4;									
			if (($now % 4) > 0 and in_array($var[3],$twobyte)) $now = ceil($now/4)*4;			
			$byter = ($var[3]=='BOOL') ? $var[1] : $byte; //либо биты либо смещение в байтах
			if ($var[3]=='Array') $var[3]=$this->type;
			$data = (isset($var[4])) ? $var[4] : '';			
			$modbus = ($var[0]!==$mod) ? (int)$now/2 : (int)($now-$byte)/2;
			$comment = (isset($var[5])) ? $var[5] : 'Адрес OWEN: '.$modbus*2;
			$return[$key] = [$modbus,$byter,$var[2],$var[3],$data,$comment];	
			$now = ($var[0]!==$mod) ? $now + $byte : $now;
			$mod = $var[0];
		}
		return $return;
	}
	
	
}
