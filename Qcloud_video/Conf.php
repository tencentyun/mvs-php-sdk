<?php
namespace Qcloud_video;

class Conf
{
    const PKG_VERSION = '1.0.0'; 

    const API_IMAGE_END_POINT = 'http://web.image.myqcloud.com/photos/v1/';
    const API_VIDEO_END_POINT = 'http://web.video.myqcloud.com/files/v1/';
    const API_COSAPI_END_POINT = 'http://web.file.myqcloud.com/files/v1/';
    //请到http://console.qcloud.com/uvs/vproject去获取你的appid、sid、skey

	const APPID = 'your appid';
    const SECRET_ID = 'your secretId';
    const SECRET_KEY = 'your secretKey';
    
    const eMaskBizAttr = 0x01;
    const eMaskTitle = 0x02;
    const eMaskDesc = 0x04;
    const eMaskVideoCover = 0x08;
    const eMaskAll = 0x0F;
	
    public static function getUA() {
        return 'QcloudPHP/'.self::PKG_VERSION.' ('.php_uname().')';
    }
}


//end of script
