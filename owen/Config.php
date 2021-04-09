<?php
namespace owen;

class Config {
	 /**
     * @var mixed[]
     */
    protected static $data = array();    
    protected static $config_file = 'config.json';  
	
	public static function init() //значения по умолчанию
	{
		self::pull();
		
		self::set('connect_errors',[
			'Необходимо передать 0 (FALSE) или 1 (TRUE)',	
			'Ошибка при получении данных - запрошено количество байт не кратное 2 или 4',
			'Неправильно указан тип данных - поддерживается REAL, FLOAT, DWORD',
			'Тип передаваемых данных должен быть указан из набора WORD, INT, DINT, REAL',
			'Не удалось установить связь с ПЛК'	
		],'');
		self::set('connect_messages',[
			'Тест соединения',
			'Начальный адрес',
			'Кол-во слов или номер бита',
			'Полученное значение',
			'Преобразованное значение',
			'Передаваемое значение',
			'Преобразованное значение'
		],'');
				
		self::push();
	}
	
	public static function default_connect() //загрузка параметров настройки соединения по умолчанию
	{
		self::pull();
		
		self::set('connect',array(
		
			'IP'=>'127.0.0.1',  //IP-адрес PLC
			'Port'=>502, //Порт
			'UnitID'=>1, //ID устройства
			'Endianess'=>'LOW_ENDIAN', //Порядок байт по умолчанию: обратный порядок: 'BIG_ENDIAN'
			
			/**
			* Формат адресов: [адрес, байт, функция чтения/записи, тип получаемой переменной, описание, название внутренней переменной, передаваемое значение]
			*/
			
			'Address1'=>[0,1,'WriteCoil', 'BOOL', 'Инициализация, переставить в нижнюю точку','inButton1',true], 
			'Address2'=>[0,2,'WriteCoil','BOOL','Старт основного теста','inButton2',true], 
			'Address3'=>[0,3,'WriteCoil','BOOL','Обнуление массы датчика','inButton3',true], 			
			'Address4'=>[0,8,'WriteCoil','BOOL','Кнопка остановки по достижению конца текущего оборота','inButtonStop','FALSE'], 
			'Address5'=>[0,9,'WriteCoil','BOOL','Кнопка немедленной остановки двигателя','inButtonStopCrash','FALSE'], //конец первого адреса ПЛК
			'Address6'=>[2,2,'ReadRegister','REAL','Температура','tempOut',''], //занимает 2,3,4 и 5 адреса ПЛК, 1 и 2 адреса модбаса
			'Address7'=>[4,2,'ReadRegister','REAL','Сырые данные с датчика массы со знаком','dtFfRealOut',''], //5 на 4 не делится, поэтому стартуем с 8 адреса ПЛК и 4 адреса модбаса, занимает 4 и 5 адреса модбаса и регистры до 0B (11) внутренние, следующий адрес модбаса -6
			'Address8'=>[6,2,'ReadRegister','REAL','Данные с вычетом массы с нулевой массой, со знаком','dtFfOut',''], //внутренний адрес - 15, адрес модбаса следующий 8
			'Address9'=>[8,2,'ReadRegister','DWORD','Счетчик энкодера','encoderOut',''], //с 16 адреса внутреннего
			'Address10'=>[10,1,'WriteRegister','WORD','Количество оборотов в тесте','countin','1'],
			'Address11'=>[11,1,'WriteRegister','WORD','Установка скорости вращения (от 0 до 1000)','speedin','15'],
			'Address12'=>[12,0,'ReadCoil','BOOL','Статус: включен режим инициализации','outStatus1',''],
			'Address13'=>[12,1,'ReadCoil','BOOL','Статус: установка в режиме теста','outStatus2',''],
			'Address14'=>[12,2,'ReadCoil','BOOL','Статус: установка в режиме запоминания массы','outStatus3',''],
			'Address15'=>[12,4,'ReadCoil','BOOL','Статус: установка в каком-то из режимов','outStatus4',''],
			//13 адрес пустой, пропущен в ПЛК
			'Address16'=>[13,1,'ReadRegister','WORD','Количество импульсов энкодера на оборот установки: чтение','4004 стоит по умолчанию',''],
			'Address17'=>[13,1,'WriteRegister','WORD','Количество импульсов энкодера на оборот установки: запись','countPulseOfEncoderin','4004'],
			'Data'=>[15,80,'ReadArray','WORD','Массив данных после одного оборота стенда','countPulseOfEncoderin',''],
		),'');	
		
		self::push();		
	} 
	
	public static function connect_addrs() //возвращает адреса соединений
	{
		$addrs = array();
		$connects = self::get('connect');	
		if (empty($connects)) throw new \Exception('Ошибка чтения конфигурации');
		foreach ($connects as $key=>$data) { //выделяем из конфига адреса соединений
			if (is_array($data)) {
				$addrs[$key]= $data;
			}		
		}
		return $addrs;
	}
	
    /**
     * Добавляет значение в конфиг
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set($key, $value, $data)
    {
		self::pull();
		if (is_array($value)) {
			self::$data[$key] = [$value,$data];
		} else {
			self::$data[$key][$value] = $data;
		}
        self::push();
    }

    /**
     * Возвращает значение из конфига по ключу
     *
     * @param string $key
     * @return mixed
     */
    public static function get($key)
    {
		self::pull();
        if (isset(self::$data[$key])) {
			return self::$data[$key][0];
		} else throw new \Exception('Ошибка чтения из конфигурации'.__DIR__.'/'.self::$config_file);
    }    
    
    protected static function push()
    {
		return file_put_contents(__DIR__.'/'.self::$config_file,json_encode(self::$data));
	}
	
	protected static function pull()
    {
		$data = file_get_contents(__DIR__.'/'.self::$config_file);
		if ($data!==FALSE) {self::$data = (array) json_decode($data);} else {
			throw new \Exception('Ошибка конфигурации');
		}	
	}
	
	public static function load_defaults()
	{						
		self::init();
		self::default_connect();			
	}
    
    public static function list()
    {
		self::pull();
		return self::$data;
	}    
	
	public static function list_with()
	{
		$list = self::list();
		foreach ($list as $key=>$one) {
		if (!empty($one[1])) {
			$data[$key] = $one;
		}
		}	
		return $data;
	}
	
    /**
     * Удаляет значение из конфига по ключу
     *
     * @param string $key
     * @return void
     */
    final public static function remove($key)
    {
		self::pull();
        if (array_key_exists($key, self::$data)) {
            unset(self::$data[$key]);
        }
        self::push();
    }
	
}
