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

class ForgeUpgrade_Db {
    const STATUS_ERROR   = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_FAILURE = 2;
    const STATUS_SKIP    = 3;

    /**
     * @var PDO
     */
    protected $dbh;

    public function __construct(PDO $dbh) {
        $this->dbh = $dbh;
        $this->t['bucket'] = 'forge_upgrade_bucket';
        $this->t['log'] = 'forge_upgrade_log';
    }

    public static function statusLabel($status) {
        $labels = array(self::STATUS_ERROR   => 'error',
                        self::STATUS_SUCCESS => 'success',
                        self::STATUS_FAILURE => 'failure',
                        self::STATUS_SKIP    => 'skipped');
        return $labels[$status];
    }
    
    public function logStart(ForgeUpgrade_Bucket $bucket) {
        $sth = $this->dbh->prepare('INSERT INTO '.$this->t['bucket'].' (script, start_date) VALUES (?, NOW())');
        if ($sth) {
            $sth->execute(array($bucket->getPath()));
            $bucket->setId($this->dbh->lastInsertId());
        }
    }

    public function logEnd(ForgeUpgrade_Bucket $bucket, $status) {
        $sth = $this->dbh->prepare('UPDATE '.$this->t['bucket'].' SET status = ?, end_date = NOW() WHERE id = ?');
        if ($sth) {
            return $sth->execute(array($status, $bucket->getId()));
        }
        return false;
    }
    
    public function getAllBuckets($status=false) {
        $stmt   = '';
        if ($status != false) {
            $escapedStatus = array_map(array($this->dbh, 'quote'), $status);
            $stmt   = ' WHERE status IN ('.implode(',', $escapedStatus).')';
        }
        return $this->dbh->query('SELECT * , TIMEDIFF(end_date, start_date) AS execution_delay FROM '.$this->t['bucket'].$stmt.' ORDER BY start_date ASC');
    }


    /**
     * Returns logs for a given bucket's execution
     * 
     * @param Integer $bucketId
     */

    public function getBucketsSummarizedLogs($bucketId) {
        return $this->dbh->query(' SELECT * , TIMEDIFF(end_date, start_date) AS execution_delay '.
                                 ' FROM '.$this->t['bucket'].
                                 ' WHERE id='.$bucketId);
    }

    /**
     * Returns detailed logs for a given bucket's execution
     * 
     * @param Integer $bucketId
     */

    public function getBucketsDetailedLogs($bucketId) {
         return $this->dbh->query(' SELECT *  '.
                                  ' FROM  '.$this->t['log']. 
                                  ' WHERE bucket_id='.$bucketId);
    }

    /**
     * 
     * @param string $sql
     * @param string $error_message
     * 
     * @return number of rows affected
     * 
     * @throws ForgeUpgrade_Bucket_Exception_UpgradeNotComplete
     */
    public function exec($sql, $error_message) {
        $res = $this->dbh->exec($sql);
        if ($res === false) {
            throw new ForgeUpgrade_Bucket_Exception_UpgradeNotComplete($error_message. ': '.implode(', ', $this->db->dbh->errorInfo()));
        }
        return $res;
    }

}

?>
