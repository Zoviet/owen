<?php 
require_once __DIR__.'/owen/Connect.php';
require_once __DIR__.'/owen/Modbus.php';
require 'vendor/autoload.php';

	//переменные для считывания и записи
	$vars = [
		'temp'=> [2,2,'ReadRegister','REAL'],
		'button_status'=> [0,1,'WriteCoil', 'BOOL','','Сообщение: считано состояние кнопки'],
		'button_push'=> [0,1,'WriteCoil', 'BOOL',true,'Сообщение: нажата кнопка'],
		'data'=>[15,80,'ReadArray','WORD'],
	];
	$connects = [
		'IP'=>'127.0.0.1',  //IP-адрес PLC
		'Port'=>502, //Порт
		'UnitID'=>1, //ID устройства
		'Endianess'=>'LOW_ENDIAN', //Порядок байт по умолчанию: обратный порядок: 'BIG_ENDIAN'
	];
	$connect =  new \owen\Connect($connects, $vars);	
	
	$temp = $connect->pull('temp');  //получение температуры
	$button = $connect->pull('button_status'); //получение статуса кнопки
	$connect->push('button_push'); //нажатие кнопки
	$data = $connect->pull('data'); //получение массива данных
	
	//var_dump($connect::$messages); //сообщения класса
	var_dump($connect::$errors); //ошибки класса	
	
	
	//с адресацией переменных ОВЕН
	$vars2 = [
		'Address1'=>[0,1,'WriteCoil', 'BOOL', true], 
		'Address2'=>[0,2,'WriteCoil','BOOL',true], 
		'Address3'=>[0,3,'WriteCoil','BOOL',true,'Обнуление массы датчика',], 			
		'Address4'=>[0,8,'WriteCoil','BOOL','FALSE'], 
		'Address5'=>[0,9,'WriteCoil','BOOL','FALSE'], //конец первого адреса ПЛК
		'Address6'=>[1,2,'ReadRegister','REAL'], //занимает 2,3,4 и 5 адреса ПЛК, 1 и 2 адреса модбаса
		'Address7'=>[2,2,'ReadRegister','REAL'], //5 на 4 не делится, поэтому стартуем с 8 адреса ПЛК и 4 адреса модбаса, занимает 4 и 5 адреса модбаса и регистры до 0B (11) внутренние, следующий адрес модбаса -6
		'Address8'=>[3,2,'ReadRegister','REAL'], //внутренний адрес - 15, адрес модбаса следующий 8
		'Address9'=>[4,2,'ReadRegister','DWORD'], //с 16 адреса внутреннего
		'Address10'=>[5,1,'WriteRegister','WORD',1],
		'Address11'=>[6,1,'WriteRegister','WORD',15],		
	];
	
	$connect2 =  new \owen\Connect($connects, $vars2,true);	
	var_dump($connect2->vars);
	
	try {
		foreach ($vars as $key=>$addr) {
			echo '<br/>	------------------'.$key.'-------------------<br/>';
			$connect->test($addr);			
			var_dump($connect::$messages);			
			echo implode('<br/>',array_slice($connect::$messages,0,5));
			echo '<br/>'.array_pop($connect::$messages).'<br/>';
			if (!empty($connect::$errors)) echo '<span class="is-danger"> Ошибки: <br/>'.implode('<br/> ',$connect::$errors).'</span>';
			sleep(0);
		}
	} catch (\Exception $e) { //если возникли ошибки, выводим их 
			echo $e->getMessage();
	}	
	$connect->close();
	echo '-------------------------------------------<br/>';
	echo nl2br($connect->modbus->status);

 ?>
