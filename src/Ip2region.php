<?php
/**
 * This file is part of Ip2region.
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Bas\Ip2region;

use Exception;

/**
 * ip2region php class
 * @see https://github.com/lionsoul2014/ip2region/tree/master/binding/php
 */

defined('INDEX_BLOCK_LENGTH') or define('INDEX_BLOCK_LENGTH', 12);
defined('TOTAL_HEADER_LENGTH') or define('TOTAL_HEADER_LENGTH', 8192);

class Ip2region
{
    /**
     * super block index info.
     */
    private $firstIndexPtr = 0;

    private $lastIndexPtr = 0;

    private $totalBlocks = 0;

    /**
     * for memory mode only
     *  the original db binary string.
     */
    private $dbBinStr = null;

    private $dbFile = null;

    private static $regionIndex = [];

    private static $ip2regionSeeker = null;

    /**
     * construct method.
     *
     * @param string $dbFile
     */
    public function __construct($dbFile = null)
    {
        if (file_exists($dbFile)) {
            $this->dbFile = $dbFile;
        } else {
            $this->dbFile = __DIR__ . '/../data/ip2region.db';
        }
    }

    /**
     * all the db binary string will be loaded into memory
     * then search the memory only and this will a lot faster than disk base search.
     *
     * @Note:
     * invoke it once before put it to public invoke could make it thread safe
     *
     * @param string $ip IPv4
     * @return array|null
     * @throws Exception
     */
    protected function memorySearch($ip)
    {
        //check and load the binary string for the first time
        if (null == $this->dbBinStr) {
            $this->dbBinStr = file_get_contents($this->dbFile);
            if (false == $this->dbBinStr) {
                throw new Exception("Fail to open the db file {$this->dbFile}");
            }

            $this->firstIndexPtr = self::getLong($this->dbBinStr, 0);
            $this->lastIndexPtr = self::getLong($this->dbBinStr, 4);
            $this->totalBlocks = ($this->lastIndexPtr - $this->firstIndexPtr) / INDEX_BLOCK_LENGTH + 1;
        }

        if (is_string($ip)) {
            $ip = self::safeIp2long($ip);
        }

        //binary search to define the data
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $this->firstIndexPtr + $m * INDEX_BLOCK_LENGTH;
            $sip = self::getLong($this->dbBinStr, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($this->dbBinStr, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($this->dbBinStr, $p + 8);
                    break;
                }
            }
        }

        //not matched just stop it here
        if (0 == $dataPtr) {
            return null;
        }

        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        return [
            'city_id' => self::getLong($this->dbBinStr, $dataPtr),
            'region' => substr($this->dbBinStr, $dataPtr + 4, $dataLen - 4),
        ];
    }

    /**
     * safe self::safeIp2long function.
     *
     * @param string $ip IPv4
     * @return bool|int|string
     */
    private static function safeIp2long($ip)
    {
        $ip = ip2long($ip);
        if (-1 == $ip || false === $ip) {
            return false;
        }

        // convert signed int to unsigned int if on 32 bit operating system
        if ($ip < 0 && PHP_INT_SIZE == 4) {
            $ip = sprintf('%u', $ip);
        }

        return $ip;
    }

    /**
     * read a long from a byte buffer.
     *
     * @param $b
     * @param $offset
     * @return int|string
     */
    private static function getLong($b, $offset)
    {
        $val = (
            (ord($b[$offset++])) |
            (ord($b[$offset++]) << 8) |
            (ord($b[$offset++]) << 16) |
            (ord($b[$offset]) << 24)
        );

        // convert signed int to unsigned int if on 32 bit operating system
        if ($val < 0 && PHP_INT_SIZE == 4) {
            $val = sprintf('%u', $val);
        }

        return $val;
    }

    /**
     * 根据 IP 获取 RegionCode 编码，6 位，实际精确到前 4 位，即：地级市。
     *
     * @param string $ip IPv4
     * @return string
     * @throws Exception
     */
    public static function getRegionCodeByIp($ip)
    {
        $info = self::getRegionInfoByIp($ip);
        $tmp = explode('|', $info);

        return isset($tmp[1]) ? $tmp[1] : '0';
    }

    /**
     * 根据 IP 获取城市信息。如：上海市 / 江苏省-南京市
     *
     * @param string $ip IPv4
     * @return string|null
     * @throws Exception
     */
    public static function getRegionNameByIp($ip)
    {
        $info = self::getRegionInfoByIp($ip);
        $tmp = explode('|', $info);
        if (!isset($tmp[1])) {
            return null;
        }
        $code = $tmp[1];

        if (empty(self::$regionIndex)) {
            self::$regionIndex = require __DIR__ . '/../data/regions/lite.php';
        }

        return (self::$regionIndex[$code]) ? self::$regionIndex[$code] : '';
    }

    /**
     * 在调用该方法前应验证 Ip(v4) 合法性.
     * 根据 IP 获取城市信息.
     *
     * .eg
     * 156|310107|CTCC
     * 国家代码 | 地区代码 | 运营商
     *
     * @param string $ip IPv4
     * @return string|null
     * @throws Exception
     */
    public static function getRegionInfoByIp($ip)
    {
        if (empty(self::$ip2regionSeeker)) {
            self::$ip2regionSeeker = new Ip2region();
        }

        return self::$ip2regionSeeker->memorySearch($ip)['region'];
    }

    /**
     * 过滤 IP 是否有效，无效时返回 false.
     *
     * @param string $ip IPv4
     * @return mixed the filtered data, or <b>FALSE</b> if the filter fails
     */
    public static function validateIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * destruct method, resource destroy.
     */
    public function __destruct()
    {
        if (null != $this->dbBinStr) {
            $this->dbBinStr = null;
        }
    }
}

// End ^ UNIX EOL ^ UTF-8
