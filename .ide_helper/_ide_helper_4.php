<?php


namespace App\Library{

    use Hashids\Hashids;

    class CurlRequest
    {
        /**
         * @param string $url
         * @param array $headers
         * @param int $timeout 单位 秒
         * @param string|false $cookie
         * @param string|false $error
         * @return string
         */
        public static function get($url, $headers = [], $timeout = 5, &$cookie = false, &$error = false)
        {}

        /**
         * @param string $url
         * @param string $post_data
         * @param array $headers
         * @param int $timeout 单位 秒
         * @param string|false $cookie
         * @return string
         */
        public static function post($url, $post_data = '', $headers = [], $timeout = 5, &$cookie = false)
        {}

        /**
         * 合并网页Cookie
         * @param string $old 旧Cookie
         * @param string $new 新Cookie
         * @return string 返回新Cookie
         */
        public static function combineCookie($old, $new)
        {}

        public static function cookieGetName($singleCookie)
        {}

        public static function cookieGetValue($singleCookie)
        {}

        /**
         * 网页_取单条Cookie
         * @param string $cookie
         * @param string $name
         * @param boolean $withName 附带名称 默认不带
         * @return string
         */
        public static function cookieGet($cookie, $name, $withName = false)
        {}
    }


    class Helper{
        public static function getMysqlDate($addDays = 0)
        {
        }

        /**
         * 获取真实IP地址
         * @return array|false|string
         */
        public static function getIP()
        {
        }


        /**
         * 获取访问这个页面的ip, 如果是cdn的话返回cdn ip
         * @return array|false|string
         */
        public static function getClientIP()
        {
        }

        /**
         * @param string $str
         * @param string|array $words abc|def
         * @return bool|string
         */
        public static function filterWords($str, $words)
        {
        }

        public static function is_idcard($id_card)
        {
        }

        public static function str_between($str, $mark1, $mark2)
        {
        }

        /**
         * 自动匹配最长段
         * @param $str
         * @param $mark1
         * @param $mark2
         * @return bool|string
         */
        public static function str_between_longest($str, $mark1, $mark2)
        {
        }

        public static function format_url($url)
        {
        }

        /**
         * @param $str
         * @return int 正整数
         */
        public static function lite_hash($str)
        {
        }

        const ID_TYPE_USER = 0;
        const ID_TYPE_CATEGORY = 1;
        const ID_TYPE_PRODUCT = 2;

        /**
         * 加密ID
         * @param $id
         * @param $type
         * @return string
         */
        public static function id_encode($id, $type)
        {
        }

        /**
         * 解密ID
         * @param $en_id
         * @param $type
         * @return int
         */
        public static function id_decode($en_id, $type)
        {
        }

        /**
         * is_mobile
         * @return boolean
         */
        public static function is_mobile()
        {
        }
    }
}