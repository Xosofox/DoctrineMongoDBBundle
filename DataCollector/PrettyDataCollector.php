<?php

/*
 * This file is part of the Doctrine MongoDBBundle
 *
 * The code was originally distributed inside the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Doctrine\Bundle\MongoDBBundle\DataCollector;

use Doctrine\MongoDB\GridFSFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A data collector that formats pretty queries.
 *
 * @author Kris Wallsmith <kris@symfony.com>
 */
class PrettyDataCollector extends StandardDataCollector
{
    private $batchInsertThreshold;

    public function setBatchInsertThreshold($batchInsertThreshold)
    {
        $this->batchInsertThreshold = $batchInsertThreshold;
    }

    public function collect(Request $request, Response $response, \Exception $exception = null)
    {
        $this->data['queries'] = [];
        $this->data['explained_queries'] = [];
        $this->data['nb_queries'] = 0;
        $this->data['total_time']=0;

        $grouped = [];
        $ordered = [];
        foreach ($this->queries as $query) {
            if (!isset($query['query']) || !isset($query['fields'])) {
                // no grouping necessary
                $ordered[] = [$query];
                continue;
            }

            $cursor = serialize($query['query']).serialize($query['fields']);

            // append if issued from cursor (currently just "sort")
            if (isset($query['sort'])) {
                unset($query['query'], $query['fields']);
                $grouped[$cursor][count($grouped[$cursor]) - 1][] = $query;
            } else {
                $grouped[$cursor][] = [$query];
                $ordered[] =& $grouped[$cursor][count($grouped[$cursor]) - 1];
            }
        }

        $i = 0;
        $db = '';
        $query = '';
        $explain = array();
        /*
         * turns all "logs" into string representations of queries
         * multiple logs can result in one query (e.g. db.coll.find().sort() ==> find & sort
         */
        //
        foreach ($ordered as $logs) {
            foreach ($logs as $log) {
                if (isset($log['db']) && $db != $log['db']) {
                    // for readability
                    $this->addQueryData('use '.$log['db'], null, true);
                    $db = $log['db'];
                }

                if (isset($log['collection'])) {
                    // flush the previous and start a new query
                    if (!empty($query)) {
                        $this->addQueryData($query, $explain);
                        $explain = array();
                    }

                    $query = 'db.'.$log['collection'];
                }

                if (isset($log["explain"])) {
                    $explain = $log["explain"];
                }

                if (isset($log["commandStats"])) {
                    $explain = $log["commandStats"];
                }

                // format the method call
                if (isset($log['aggregate'])) {
                    $query .= '.aggregate(' . $this->bsonEncode($log['pipeline']);

                    if ($log['options']) {
                        $query .= ', ' . $this->bsonEncode($log['options']);
                    }

                    $query .= ')';
                } elseif (isset($log['authenticate'])) {
                    $query .= '.authenticate()';
                } elseif (isset($log['batchInsert'])) {
                    if (1 === $log['num']) {
                        $query .= '.insert('.$this->bsonEncode($log['data']).')';
                    } elseif (null !== $this->batchInsertThreshold && $this->batchInsertThreshold <= $log['num']) {
                        $query .= '.batchInsert(**'.$log['num'].' items**)';
                    } else {
                        $query .= '.batchInsert('.$this->bsonEncode($log['data']).')';
                    }
                } elseif (isset($log['command'])) {
                    $query .= '.runCommand('.$this->bsonEncode($log['data']).')';
                } elseif (isset($log['storeFile'])) {
                    $query .= '.storeFile('.$log['count'].', '.$this->bsonEncode($log['options']).')';
                } elseif (isset($log['count'])) {
                    $query .= '.count(';
                    if (isset($log['query']) || isset($log['limit']) || isset($log['skip'])) {
                        $query .= $this->bsonEncode(isset($log['query']) ? $log['query'] : [], false);

                        $options = [];
                        if (isset($log['limit'])) {
                            $options['limit'] = $log['limit']['limitNum'];
                        }
                        if (isset($log['skip'])) {
                            $options['skip'] = $log['skip']['limitSkip'];
                        }

                        if (!empty($options)) {
                            $query .= ', '.$this->bsonEncode($options, false);
                        }
                    }
                    $query .= ')';
                } elseif (isset($log['skip'])) {
                    $query .= '.skip('.$log['skipNum'].')';
                } elseif (isset($log['limit']) && isset($log['limitNum'])) {
                    $query .= '.limit('.$log['limitNum'].')';
                } elseif (isset($log['createCollection'])) {
                    $query .= '.createCollection()';
                } elseif (isset($log['createDBRef'])) {
                    $query .= '.createDBRef()';
                } elseif (isset($log['deleteIndex'])) {
                    $query .= '.dropIndex('.$this->bsonEncode($log['keys']).')';
                } elseif (isset($log['deleteIndexes'])) {
                    $query .= '.dropIndexes()';
                } elseif (isset($log['drop'])) {
                    $query .= '.drop()';
                } elseif (isset($log['dropDatabase'])) {
                    $query .= '.dropDatabase()';
                } elseif (isset($log['ensureIndex'])) {
                    $query .= '.ensureIndex('.$this->bsonEncode($log['keys']).', '.$this->bsonEncode($log['options']).')';
                } elseif (isset($log['execute'])) {
                    $query .= '.execute()';
                } elseif (isset($log['find'])) {
                    $query .= '.find(';
                    if (isset($log['query']) || isset($log['fields'])) {
                        $query .= $this->bsonEncode(isset($log['query']) ? $log['query'] : [], false);
                        if (!empty($log['fields'])) {
                            $query .= ', '.$this->bsonEncode($log['fields']);
                        }
                    }
                    $query .= ')';
                } elseif (isset($log['findOne'])) {
                    $query .= '.findOne(';
                    if (isset($log['query']) || isset($log['fields'])) {
                        $query .= $this->bsonEncode(isset($log['query']) ? $log['query'] : [], false);
                        if (!empty($log['fields'])) {
                            $query .= ', '.$this->bsonEncode($log['fields']);
                        }
                    }
                    $query .= ')';
                } elseif (isset($log['getDBRef'])) {
                    $query .= '.getDBRef()';
                } elseif (isset($log['group'])) {
                    $query .= '.group('.$this->bsonEncode([
                        'key'    => $log['keys'],
                        'initial' => $log['initial'],
                        'reduce'  => $log['reduce'],
                        ]).')';
                } elseif (isset($log['insert'])) {
                    $query .= '.insert('.$this->bsonEncode($log['document']).')';
                } elseif (isset($log['remove'])) {
                    $query .= '.remove('.$this->bsonEncode($log['query']).')';
                } elseif (isset($log['save'])) {
                    $query .= '.save('.$this->bsonEncode($log['document']).')';
                } elseif (isset($log['sort'])) {
                    $query .= '.sort('.$this->bsonEncode($log['sortFields'], false).')';
                } elseif (isset($log['update'])) {
                    // todo: include $log['options']
                    $query .= '.update('.$this->bsonEncode($log['query']).', '.$this->bsonEncode($log['newObj']).')';
                } elseif (isset($log['validate'])) {
                    $query .= '.validate()';
                }
            }
        }

        $this->addQueryData($query, $explain);
    }

    /**
     * @todo Move this to a collaborator
     */
    private function bsonEncode($query, $array = true)
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if (!is_numeric($key)) {
                $array = false;
            }

            if (null === $value) {
                $formatted = 'null';
            } elseif (is_bool($value)) {
                $formatted = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $formatted = $value;
            } elseif (is_scalar($value)) {
                $formatted = '"'.$value.'"';
            } elseif (is_array($value)) {
                $formatted = $this->bsonEncode($value);
            } elseif ($value instanceof \MongoId) {
                $formatted = 'ObjectId("'.$value.'")';
            } elseif ($value instanceof \MongoDate) {
                $formatted = 'new ISODate("'.date('c', $value->sec).'")';
            } elseif ($value instanceof \DateTime) {
                $formatted = 'new ISODate("'.date('c', $value->getTimestamp()).'")';
            } elseif ($value instanceof \MongoRegex) {
                $formatted = 'new RegExp("'.$value->regex.'", "'.$value->flags.'")';
            } elseif ($value instanceof \MongoMinKey) {
                $formatted = 'new MinKey()';
            } elseif ($value instanceof \MongoMaxKey) {
                $formatted = 'new MaxKey()';
            } elseif ($value instanceof \MongoBinData) {
                $formatted = 'new BinData('.$value->type.', "'.base64_encode($value->bin).'")';
            } elseif ($value instanceof \MongoGridFSFile || $value instanceof GridFSFile) {
                $formatted = 'new MongoGridFSFile("'.$value->getFilename().'")';
            } elseif ($value instanceof \stdClass) {
                $formatted = $this->bsonEncode((array) $value);
            } else {
                $formatted = (string) $value;
            }

            $parts['"'.$key.'"'] = $formatted;
        }

        if (0 == count($parts)) {
            return $array ? '[ ]' : '{ }';
        }

        if ($array) {
            return '[ '.implode(', ', $parts).' ]';
        } else {
            $mapper = function ($key, $value) {
                return $key.': '.$value;
            };

            return '{ '.implode(', ', array_map($mapper, array_keys($parts), array_values($parts))).' }';
        }
    }

    /**
     * @param string $query query code as reconstructed from logs
     * @param array "explain data" coming from cursor->explain()
     * @param bool $informational to indicate "not actual queries" that are added for readability
     */
    private function addQueryData($query, $explain = array(), $informational = false)
    {
        if (empty($query)) {
            return;
        }

        if ('.' == $query[0]) {
            $query = 'db'.$query;
        }
        $query .= ';';

        $this->data["queries"][] = $query;

        if (!$informational) {
            $this->data['nb_queries']++;
        }

        $data = array(
            "query" => $query,
            "informational" => $informational,
        );

        if ($explain) {
            $data["explain"] = $explain;
            //pick some fields for display in panel
            if (isset($explain["queryPlanner"]["winningPlan"]["stage"])) {
                $data["plan"] = $explain["queryPlanner"]["winningPlan"]["stage"];
            }
            if (isset($explain["executionStats"]["executionTimeMillis"])) {
                $ms = $explain["executionStats"]["executionTimeMillis"];
                $data["ms"] = $ms;
                $this->data['total_time']+=$ms;
            }
            //from command stats
            if (isset($explain["time"])) {
                $data["plan"] = 'COMMAND nscanned '.$explain['nscanned'];
                $ms = $explain["time"];
                $data["ms"] = $ms;
                $this->data['total_time']+=$ms;
            }
        }

        $this->data["explained_queries"][] = $data;
    }
}
