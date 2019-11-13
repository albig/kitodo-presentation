<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Hooks;

use Kitodo\Dlf\Common\Document;
use Kitodo\Dlf\Common\Helper;
use Kitodo\Dlf\Common\Indexer;
use Kitodo\Dlf\Common\Solr;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Hooks and helper for \TYPO3\CMS\Core\DataHandling\DataHandler
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class DataHandler
{
    /**
     * Field post-processing hook for the process_datamap() method.
     *
     * @access public
     *
     * @param string $status: 'new' or 'update'
     * @param string $table: The destination table
     * @param int $id: The uid of the record
     * @param array &$fieldArray: Array of field values
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj: The parent object
     *
     * @return void
     */
    public function processDatamap_postProcessFieldArray($status, $table, $id, &$fieldArray, $pObj)
    {
        if ($status == 'new') {
            switch ($table) {
                    // Field post-processing for table "tx_dlf_documents".
                case 'tx_dlf_documents':
                    // Set sorting field if empty.
                    if (
                        empty($fieldArray['title_sorting'])
                        && !empty($fieldArray['title'])
                    ) {
                        $fieldArray['title_sorting'] = $fieldArray['title'];
                    }
                    break;
                    // Field post-processing for table "tx_dlf_metadata".
                case 'tx_dlf_metadata':
                    // Store field in index if it should appear in lists.
                    if (!empty($fieldArray['is_listed'])) {
                        $fieldArray['index_stored'] = 1;
                    }
                    // Index field in index if it should be used for auto-completion.
                    if (!empty($fieldArray['index_autocomplete'])) {
                        $fieldArray['index_indexed'] = 1;
                    }
                    // Field post-processing for tables "tx_dlf_metadata", "tx_dlf_collections", "tx_dlf_libraries" and "tx_dlf_structures".
                case 'tx_dlf_collections':
                case 'tx_dlf_libraries':
                case 'tx_dlf_structures':
                    // Set label as index name if empty.
                    if (
                        empty($fieldArray['index_name'])
                        && !empty($fieldArray['label'])
                    ) {
                        $fieldArray['index_name'] = $fieldArray['label'];
                    }
                    // Set index name as label if empty.
                    if (
                        empty($fieldArray['label'])
                        && !empty($fieldArray['index_name'])
                    ) {
                        $fieldArray['label'] = $fieldArray['index_name'];
                    }
                    // Ensure that index names don't get mixed up with sorting values.
                    if (substr($fieldArray['index_name'], -8) == '_sorting') {
                        $fieldArray['index_name'] .= '0';
                    }
                    break;
                    // Field post-processing for table "tx_dlf_solrcores".
                case 'tx_dlf_solrcores':
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('tx_dlf_solrcores');

                    // Get number of existing cores.
                    $result = $queryBuilder
                        ->select('*')
                        ->from('tx_dlf_solrcores')
                        ->execute();

                    // Get first unused core number.
                    $coreNumber = Solr::solrGetCoreNumber(count($result->fetchAll()));
                    // Get Solr credentials.
                    $conf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['dlf']);
                    $solrInfo = Solr::getSolrConnectionInfo();
                    // Prepend username and password to hostname.
                    if (
                        $solrInfo['username']
                        && $solrInfo['password']
                    ) {
                        $host = $solrInfo['username'] . ':' . $solrInfo['password'] . '@' . $solrInfo['host'];
                    } else {
                        $host = $solrInfo['host'];
                    }
                    $context = stream_context_create([
                        'http' => [
                            'method' => 'GET',
                            'user_agent' => ($conf['useragent'] ? $conf['useragent'] : ini_get('user_agent'))
                        ]
                    ]);
                    // Build request for adding new Solr core.
                    // @see http://wiki.apache.org/solr/CoreAdmin
                    $url = $solrInfo['scheme'] . '://' . $host . ':' . $solrInfo['port'] . '/' . $solrInfo['path'] . '/admin/cores?wt=xml&action=CREATE&name=dlfCore' . $coreNumber . '&instanceDir=dlfCore' . $coreNumber . '&dataDir=data&configSet=dlf';
                    $response = @simplexml_load_string(file_get_contents($url, false, $context));
                    // Process response.
                    if ($response) {
                        $solrStatus = $response->xpath('//lst[@name="responseHeader"]/int[@name="status"]');
                        if (
                            is_array($solrStatus)
                            && $solrStatus[0] == 0
                        ) {
                            $fieldArray['index_name'] = 'dlfCore' . $coreNumber;
                            return;
                        }
                    }
                    Helper::devLog('Could not create new Apache Solr core "dlfCore' . $coreNumber . '"', DEVLOG_SEVERITY_ERROR);
                    // Solr core could not be created, thus unset field array.
                    $fieldArray = [];
                    break;
            }
        } elseif ($status == 'update') {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);

            switch ($table) {
                    // Field post-processing for table "tx_dlf_metadata".
                case 'tx_dlf_metadata':
                    // Store field in index if it should appear in lists.
                    if (!empty($fieldArray['is_listed'])) {
                        $fieldArray['index_stored'] = 1;
                    }
                    if (
                        isset($fieldArray['index_stored'])
                        && $fieldArray['index_stored'] == 0
                        && !isset($fieldArray['is_listed'])
                    ) {
                        // Get current configuration.
                        $result = $queryBuilder
                            ->select($table . '.is_listed AS is_listed')
                            ->from($table)
                            ->where(
                                $queryBuilder->expr()->eq($table . '.uid', intval($id)),
                                Helper::whereExpression($table)
                            )
                            ->setMaxResults(1)
                            ->execute();

                        if ($resArray = $result->fetch()) {
                            // Reset storing to current.
                            $fieldArray['index_stored'] = $resArray['is_listed'];
                        }
                    }
                    // Index field in index if it should be used for auto-completion.
                    if (!empty($fieldArray['index_autocomplete'])) {
                        $fieldArray['index_indexed'] = 1;
                    }
                    if (
                        isset($fieldArray['index_indexed'])
                        && $fieldArray['index_indexed'] == 0
                        && !isset($fieldArray['index_autocomplete'])
                    ) {
                        // Get current configuration.
                        $result = $queryBuilder
                            ->select($table . '.index_autocomplete AS index_autocomplete')
                            ->from($table)
                            ->where(
                                $queryBuilder->expr()->eq($table . '.uid', intval($id)),
                                Helper::whereExpression($table)
                            )
                            ->setMaxResults(1)
                            ->execute();

                        if ($resArray = $result->fetch()) {
                            // Reset indexing to current.
                            $fieldArray['index_indexed'] = $resArray['index_autocomplete'];
                        }
                    }
                    // Field post-processing for tables "tx_dlf_metadata" and "tx_dlf_structures".
                case 'tx_dlf_structures':
                    // The index name should not be changed in production.
                    if (isset($fieldArray['index_name'])) {
                        if (count($fieldArray) < 2) {
                            // Unset the whole field array.
                            $fieldArray = [];
                        } else {
                            // Get current index name.
                            $result = $queryBuilder
                                ->select($table . '.index_autocomplete AS index_autocomplete')
                                ->from($table)
                                ->where(
                                    $queryBuilder->expr()->eq($table . '.uid', intval($id)),
                                    Helper::whereExpression($table)
                                )
                                ->setMaxResults(1)
                                ->execute();

                            if ($resArray = $result->fetch()) {
                                // Reset indexing to current.
                                $fieldArray['index_indexed'] = $resArray['index_autocomplete'];
                            }
                        }
                        Helper::devLog('Prevented change of index_name for UID ' . $id . ' in table "' . $table . '"', DEVLOG_SEVERITY_NOTICE);
                    }
                    break;
            }
        }
    }

    /**
     * After database operations hook for the process_datamap() method.
     *
     * @access public
     *
     * @param string $status: 'new' or 'update'
     * @param string $table: The destination table
     * @param int $id: The uid of the record
     * @param array &$fieldArray: Array of field values
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj: The parent object
     *
     * @return void
     */
    public function processDatamap_afterDatabaseOperations($status, $table, $id, &$fieldArray, $pObj)
    {
        if ($status == 'update') {
            switch ($table) {
                    // After database operations for table "tx_dlf_documents".
                case 'tx_dlf_documents':
                    // Delete/reindex document in Solr if "hidden" status or collections have changed.
                    if (
                        isset($fieldArray['hidden'])
                        || isset($fieldArray['collections'])
                    ) {
                        // Get Solr core.
                        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                            'tx_dlf_solrcores.uid,tx_dlf_documents.hidden',
                            'tx_dlf_solrcores,tx_dlf_documents',
                            'tx_dlf_solrcores.uid=tx_dlf_documents.solrcore'
                                . ' AND tx_dlf_documents.uid=' . intval($id)
                                . Helper::whereClause('tx_dlf_solrcores'),
                            '',
                            '',
                            '1'
                        );
                        if ($GLOBALS['TYPO3_DB']->sql_num_rows($result)) {
                            list($core, $hidden) = $GLOBALS['TYPO3_DB']->sql_fetch_row($result);
                            if ($hidden) {
                                // Establish Solr connection.
                                if ($solr = Solr::getInstance($core)) {
                                    // Delete Solr document.
                                    $updateQuery = $solr->service->createUpdate();
                                    $updateQuery->addDeleteQuery('uid:' . $id);
                                    $updateQuery->addCommit();
                                    $solr->service->update($updateQuery);
                                }
                            } else {
                                // Reindex document.
                                $doc = Document::getInstance($id);
                                if ($doc->ready) {
                                    Indexer::add($doc, $core);
                                } else {
                                    Helper::devLog('Failed to re-index document with UID ' . $id, DEVLOG_SEVERITY_ERROR);
                                }
                            }
                        }
                    }
                    break;
            }
        }
    }

    /**
     * Post-processing hook for the process_cmdmap() method.
     *
     * @access public
     *
     * @param string $command: 'move', 'copy', 'localize', 'inlineLocalizeSynchronize', 'delete' or 'undelete'
     * @param string $table: The destination table
     * @param int $id: The uid of the record
     * @param mixed $value: The value for the command
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $pObj: The parent object
     *
     * @return void
     */
    public function processCmdmap_postProcess($command, $table, $id, $value, $pObj)
    {
        if (
            in_array($command, ['move', 'delete', 'undelete'])
            && $table == 'tx_dlf_documents'
        ) {
            // Get Solr core.
            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                'tx_dlf_solrcores.uid',
                'tx_dlf_solrcores,tx_dlf_documents',
                'tx_dlf_solrcores.uid=tx_dlf_documents.solrcore'
                    . ' AND tx_dlf_documents.uid=' . intval($id)
                    . Helper::whereClause('tx_dlf_solrcores'),
                '',
                '',
                '1'
            );
            if ($GLOBALS['TYPO3_DB']->sql_num_rows($result)) {
                list($core) = $GLOBALS['TYPO3_DB']->sql_fetch_row($result);
                switch ($command) {
                    case 'move':
                    case 'delete':
                        // Establish Solr connection.
                        if ($solr = Solr::getInstance($core)) {
                            // Delete Solr document.
                            $updateQuery = $solr->service->createUpdate();
                            $updateQuery->addDeleteQuery('uid:' . $id);
                            $updateQuery->addCommit();
                            $solr->service->update($updateQuery);
                            if ($command == 'delete') {
                                break;
                            }
                        }
                    case 'undelete':
                        // Reindex document.
                        $doc = Document::getInstance($id);
                        if ($doc->ready) {
                            Indexer::add($doc, $core);
                        } else {
                            Helper::devLog('Failed to re-index document with UID ' . $id, DEVLOG_SEVERITY_ERROR);
                        }
                        break;
                }
            }
        }
    }
}
