<?php
/**
 * Copyright (c) STMicroelectronics, 2010. All Rights Reserved.
 *
 * Originally written by Manuel Vacelet
 *
 * ForgeUpgrade is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * ForgeUpgrade is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with ForgeUpgrade. If not, see <http://www.gnu.org/licenses/>.
 */

require 'bucket/Bucket.php';
require 'BucketFilter.php';
require 'db/Db.php';

/**
 * Centralize upgrade of the Forge
 */
class ForgeUpgrade {
    /**
     * @var ForgeUpgrade_Db_Driver_Abstract
     */
    protected $dbDriver;

    /**
     * @var ForgeUpgradeDb
     */
    protected $db;

    /**
     * Contains all bucket API
     * @var Array
     */
    protected $bucketApi = array();

    /**
     * @var Logger
     */
    protected $log;

    /**
     * @var array
     */
    protected $buckets = null;

    /**
     * Constructor
     */
    public function __construct(ForgeUpgrade_Db_Driver_Abstract $dbDriver, Upgrade $upgrader) {
        $this->dbDriver = $dbDriver;
        $this->db       = new ForgeUpgrade_Db($dbDriver->getPdo());
        $this->bucketApi['ForgeUpgrade_Bucket_Db'] = new ForgeUpgrade_Bucket_Db($dbDriver->getPdo());
        $this->upgrader = $upgrader;
    }

    /**
     * Set all options of forge upgrade
     * 
     * If an option is not set, fill with default
     * 
     * @param Array $options
     * 
     * @return void
     */
    function setOptions(array $options) {
        if (!isset($options['core']['path'])) {
            $options['core']['path']         = array();
        }
        if (!isset($options['core']['include_path'])) {
            $options['core']['include_path'] = array();
        }
        if (!isset($options['core']['exclude_path'])) {
            $options['core']['exclude_path'] = array();
        }
        if (!isset($options['core']['dbdriver'])) {
            $options['core']['dbdriver']     = null;
        }
        if (!isset($options['core']['ignore_preup'])) {
            $options['core']['ignore_preup'] = false;
        }
        if (!isset($options['core']['force'])) {
            $options['core']['force']        = false;
        }
        if (!isset($options['core']['bucket'])) {
            $options['core']['bucket']     = null;
        }
        $this->options = $options;
    }




    /**
     * Return all the buckets not already applied
     * 
     * @param array $dirPath
     */
    private function getBucketsToProceed(array $dirPath) {
        if (!isset($this->buckets)) {
            $this->buckets = $this->getAllBuckets($dirPath);
            $sth           = $this->db->getAllBuckets(array(ForgeUpgrade_Db::STATUS_SUCCESS, ForgeUpgrade_Db::STATUS_SKIP));
            foreach($sth as $row) {
                $key = basename($row['script']);
                if (isset($this->buckets[$key])) {
                    $this->log()->debug("Remove (already applied): $key");
                    unset($this->buckets[$key]);
                }
            }
        }
        return $this->buckets;
    }

    /**
     * Find all migration files and sort them in time order
     *
     * @return Array of SplFileInfo
     */
    private function getAllBuckets(array $paths) {
        $buckets = array();
        foreach($paths as $path) {
            $this->log()->debug("Look for buckets in $path");
            $this->findAllBucketsInPath($path, $buckets);
        }
        ksort($buckets, SORT_STRING);
        return $buckets;
    }

    /**
     * Fill $buckets array with all available buckets in $path
     * 
     * @param String $path
     * @param Array $buckets
     */
    private function findAllBucketsInPath($path, &$buckets) {
        if (is_dir($path)) {
            $iter = $this->getBucketFinderIterator($path);
            foreach ($iter as $file) {
                $this->queueMigrationBucket($file, $buckets);
            }
        } else {
            $this->queueMigrationBucket(new SplFileInfo($path), $buckets);
        }
    }

    /**
     * Build iterator to find buckets in a file hierarchy
     * 
     * @param String $dirPath
     * 
     * @return ForgeUpgrade_BucketFilter
     */
    private function getBucketFinderIterator($dirPath) {
        $iter = new RecursiveDirectoryIterator($dirPath);
        $iter = new RecursiveIteratorIterator($iter, RecursiveIteratorIterator::SELF_FIRST);
        $iter = new ForgeUpgrade_BucketFilter($iter);
        $iter->setIncludePaths($this->options['core']['include_path']);
        $iter->setExcludePaths($this->options['core']['exclude_path']);
        return $iter;
    }

    /**
     * Append a bucket in the bucket candidate list
     * 
     * @param SplFileInfo $file
     * 
     * @return void
     */
    private function queueMigrationBucket(SplFileInfo $file, &$buckets) {
        if ($file->isFile()) {
            $object = $this->getBucketClass($file);
            if ($object) {
                $this->log()->debug("Valid bucket: $file");
                $buckets[basename($file->getPathname())] = $object;
            } else {
                $this->log()->debug("Invalid bucket: $file");
            }
        }
    }

    /**
     * Create a new bucket object defined in given file
     * 
     * @param SplFileInfo $scriptPath Path to the bucket definition
     * 
     * @return ForgeUpgrade_Bucket
     */
    private function getBucketClass(SplFileInfo $scriptPath) {
        $bucket = null;
        $class  = $this->getClassName($scriptPath->getPathname());
        if (!class_exists($class)) {
            include $scriptPath->getPathname();
        }
        if ($class != '' && class_exists($class)) {
            $bucket = new $class();
            $bucket->setPath($scriptPath->getPathname());
            $this->addBucketApis($bucket);
        }
        return $bucket;
    }

    /**
     * Add all available API to the given bucket
     * 
     * @param ForgeUpgrade_Bucket $bucket
     * 
     * @return void
     */
    private function addBucketApis(ForgeUpgrade_Bucket $bucket) {
        $bucket->setAllApi($this->bucketApi);
    }

    /**
     * Deduce the class name from the script name
     *
     * migrations/201004081445_add_tables_for_docman_watermarking.php -> b201004081445_add_tables_for_docman_watermarking
     *
     * @param String $scriptPath Path to the script to execute
     *
     * @return String
     */
    private function getClassName($scriptPath) {
        return 'b'.basename($scriptPath, '.php');
    }

    /**
     * Run all available migrations
     */
    public function run($func) {
        // Commands without path
        switch ($func) {
            case 'already-applied':
                $upgrader = new AlreadyApplied($this->db, $this->options['core']['bucket']);
                $upgrader->proceed(null);
                return;
        }
        
        // Commands that rely on path
        if (count($this->options['core']['path']) == 0) {
            $this->log()->error('No migration path');
            return false;
        }
        $buckets = $this->getBucketsToProceed($this->options['core']['path']);
        if (count($buckets) > 0) {
            $this->upgrader->proceed($buckets);
        } else {
            $this->log()->info('System up-to-date');
        }
    }

}

class AlreadyApplied implements Upgrade {
    public function __construct(ForgeUpgrade_Db $db, $bucket) {
        $this->db = $db;
        $this->bucket = $bucket;
    }
        /**
     * Displays detailed bucket's logs for a given bucket Id 
     * Or all buckets' logs according to the option "bucket" is filled or not
     */
    public function proceed($buckets) {
        if ($this->bucket) {
            $this->displayAlreadyAppliedPerBucket($this->bucket);
        } else {
            $this->displayAlreadyAppliedForAllBuckets();
        }
    }
    
    /**
     * Displays detailed bucket's logs for a given bucket Id
     * 
     * @param Integer $bucketId
     */
    private function displayAlreadyAppliedPerBucket($bucketId) {
        echo '';
        $summary = $this->db->getBucketsSummarizedLogs($bucketId);
        if ($summary) {
            echo 'Start date'."           ".'Execution'."  ".'Status'."  ".'Id'."  ".'Script'.PHP_EOL;
            $logs = $summary->fetchAll();
            echo($this->displayColoriedStatus($logs[0]));
        }

       echo "Detailed logs execution for bucket ".$bucketId.PHP_EOL;
       $details = $this->db->getBucketsDetailedLogs($bucketId);
       if ($details) {
           echo 'Start date'."           ".'Level'."  ".'Message'.PHP_EOL;
           foreach ($details->fetchAll() as $row) {
               $level = $row['level'];
               $message = $row['timestamp']."  ".$level."  ".$row['message'].PHP_EOL;
               echo LoggerAppenderConsoleColor::chooseColor($level, $message);
           }
       }
    }


    /**
     * Displays logs of all buckets already applied 
     */
    private function displayAlreadyAppliedForAllBuckets() {
        echo 'start date'."           ".'Execution'."  ".'Status'."  ".'Id'."  ".'Script'.PHP_EOL;
        foreach ($this->db->getAllBuckets() as $row) {
            echo $this->displayColoriedStatus($row);
        }
    }


    private function displayColoriedStatus($info) {
        $status = $this->db->statusLabel($info['status']);
        switch ($status) {
            case 'error':
            case 'failure':
                $color = LoggerAppenderConsoleColor::RED;
                break;

           case 'success':
                $color = LoggerAppenderConsoleColor::GREEN;
                break;

           case 'skipped':
                $color = LoggerAppenderConsoleColor::YELLOW;
                break;

           default:
                break;
        }
        return $color.($info['start_date']."  ".$info['execution_delay']."  ".ucfirst($status)."  ".$info['id']."  ".$info['script'].PHP_EOL.LoggerAppenderConsoleColor::NOCOLOR);
    }


}

class RecordOnly implements Upgrade {
    public function __construct(ForgeUpgrade_Db $db) {
        $this->db       = $db;
    }
    
    public function proceed($buckets) {
        foreach ($buckets as $bucket) {
            $this->log()->info("[doRecordOnly] ".get_class($bucket));
            $this->db->logStart($bucket);
            $this->db->logEnd($bucket, ForgeUpgrade_Db::STATUS_SKIP);
        
        }
    }
    
    private function log() {
        if (!$this->log) {
            $this->log = Logger::getLogger(get_class());
        }
        return $this->log;
    }
    
    
}
class CheckUpdate implements Upgrade {
    
    public function proceed($buckets) {
        foreach ($buckets as $bucket) {
            echo $bucket->getPath().PHP_EOL;
            $lines = explode("\n", $bucket->description());
            foreach ($lines as $line) {
                echo "\t$line\n";
            }
        }
        echo count($buckets)." migrations pending\n";
    }

}
interface Upgrade {
    function proceed($buckets);
}

?>
