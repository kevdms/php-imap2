<?php

/*
 * This file is part of the PHP Input package.
 *
 * (c) Francesco Bianco <bianco@javanile.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Javanile\Imap2;

use Javanile\Imap2\ImapClient;

class BodyStructure
{
    protected static $encodingNumber = [
        '8BIT' => 1,
        'BASE64' => 3,
        'QUOTED-PRINTABLE' => 4,
    ];

    public static function fromMessage($message)
    {
        return self::fromBodyStructure($message->bodystructure);
    }

    protected static function fromBodyStructure($structure)
    {
        $parts = [];
        $parameters = [];

        file_put_contents('t3.json', json_encode($structure, JSON_PRETTY_PRINT));
        #die();

        if (isset($structure[0]) && $structure[0] == 'TEXT') {
            return self::textStructure($structure);
        }

        $section = 'parts';
        $subType = 'ALTERNATIVE';
        foreach ($structure as $item) {
            if ($item == 'ALTERNATIVE') {
                $section = 'parameters';
                continue;
            }

            if ($item == 'MIXED') {
                $subType = 'MIXED';
                $section = 'parameters';
                continue;
            }

            if ($section == 'parts') {
                $parts[] = self::extractPart($item);
            } elseif (is_array($item)) {
                $parameters = self::extractParameters($item, $parameters);
            }
        }

        return (object) [
            'type' => 1,
            'encoding' => 0,
            'ifsubtype' => 1,
            'subtype' => $subType,
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => 1,
            'parameters' => $parameters,
            'parts' => $parts,
        ];
    }

    protected static function extractPart($item)
    {
        if ($item[2] == 'RELATED') {
            return self::extractPartAsRelated($item);
        }

        if ($item[2] == 'ALTERNATIVE') {
            return self::extractPartAsAlternative($item);
        }

        $attribute = null;
        $parameters = [];

        if (!is_array($item[2])) {
            var_dump($item);

            return $parameters;
        }

        foreach ($item[2] as $value) {
            if (empty($attribute)) {
                $attribute = [
                    'attribute' => $value,
                    'value' => null,
                ];
            } else {
                $attribute['value'] = $value;
                $parameters[] = (object) $attribute;
                $attribute = null;
            }
        }

        $type = 0;
        $linesIndex = 7;
        $bytesIndex = 6;
        if ($item[0] == 'MESSAGE') {
            $type = 2;
            $linesIndex = 9;
        }
        if ($item[0] == 'IMAGE') {
            $type = 5;
            $linesIndex = 9;
        }
        if ($item[1] == 'JPEG') {
            #var_dump($item);
            #die();
        }

        $part = (object) [
            'type' => $type,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => $item[1],
            'ifdescription' => 0,
            'description' => null,
            'ifid' => 0,
            'id' => null,
            'lines' => intval($item[$linesIndex]),
            'bytes' => intval($item[$bytesIndex]),
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => 1,
            'parameters' => $parameters,
        ];

        if ($item[3]) {
            $part->ifid = 1;
            $part->id = $item[3];
        } else {
            unset($part->id);
        }

        if ($item[4]) {
            $part->ifdescription = 1;
            $part->description = $item[4];
        } else {
            unset($part->description);
        }

        if ($type == 5) {
            unset($part->lines);
        }

        $dispositionIndex = 9;
        if ($type == 2) {
            $dispositionIndex = 11;
        } elseif ($type == 5) {
            $dispositionIndex = 8;
        }
        if (isset($item[$dispositionIndex][0])) {
            $attribute = null;
            $dispositionParameters = [];
            $part->disposition = $item[$dispositionIndex][0];
            if (isset($item[$dispositionIndex][1]) && is_array($item[$dispositionIndex][1])) {
                foreach ($item[$dispositionIndex][1] as $value) {
                    if (empty($attribute)) {
                        $attribute = [
                            'attribute' => $value,
                            'value' => null,
                        ];
                    } else {
                        $attribute['value'] = $value;
                        $dispositionParameters[] = (object)$attribute;
                        $attribute = null;
                    }
                }
            }
            $part->dparameters = $dispositionParameters;
            $part->ifdparameters = 1;
            $part->ifdisposition = 1;
        } else {
            unset($part->disposition);
            unset($part->dparameters);
        }

        return self::processSubParts($item, $part);
    }

    protected static function extractPartAsRelated($item)
    {
        $part = (object) [
            'type' => 1,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => 'RELATED',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => 1,
            'parameters' => [],
            'parts' => []
        ];

        $offsetIndex = 0;
        foreach ($item as $subPart) {
            if (!is_array($subPart)) {
                break;
            }
            $offsetIndex++;
            $part->parts[] = self::extractPart($subPart);
        }

        $part->parameters = self::extractParameters($item[$offsetIndex+1], []);

        unset($part->disposition);
        unset($part->dparameters);

        return $part;
    }

    protected static function extractPartAsAlternative($item)
    {
        $part = (object) [
            'type' => 1,
            'encoding' => self::getEncoding($item, 5),
            'ifsubtype' => 1,
            'subtype' => 'ALTERNATIVE',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'disposition' => null,
            'ifdparameters' => 0,
            'dparameters' => null,
            'ifparameters' => 1,
            'parameters' => [],
            'parts' => []
        ];

        $offsetIndex = 0;
        foreach ($item as $subPart) {
            if (!is_array($subPart)) {
                break;
            }
            $offsetIndex++;
            $part->parts[] = self::extractPart($subPart);
        }

        $part->parameters = self::extractParameters($item[$offsetIndex+1], []);

        unset($part->disposition);
        unset($part->dparameters);

        return $part;
    }

    protected static function processSubParts($item, $part)
    {
        if ($item[0] != 'MESSAGE') {
            return $part;
        }

        $part->parts = [
            self::processSubPartAsMessage($item)
        ];

        return $part;
    }
    
    protected static function processSubPartAsMessage($item)
    {
        $message = (object) [
            'type' => 1,
            'encoding' => 0,
            'ifsubtype' => 1,
            'subtype' => 'MIXED',
            'ifdescription' => 0,
            'ifid' => 0,
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => 1,
            'parameters' => [
                (object) [
                    'attribute' => 'BOUNDARY',
                    'value' => '=_995890bdbf8bd158f2cbae0e8d966000'
                ]
            ],
            'parts' => [

            ]
        ];

        foreach ($item[8] as $itemPart) {
            if (!is_array($itemPart[2])) {
                continue;
            }

            $parameters = self::extractParameters($itemPart[2], []);

            $part = (object) [
                'type' => 0,
                'encoding' => self::getEncoding($itemPart, 5),
                'ifsubtype' => 1,
                'subtype' => 'PLAIN',
                'ifdescription' => 0,
                'ifid' => 0,
                'lines' => intval($itemPart[7]),
                'bytes' => intval($itemPart[6]),
                'ifdisposition' => 0,
                'disposition' => [],
                'ifdparameters' => 0,
                'dparameters' => [],
                'ifparameters' => 1,
                'parameters' => $parameters
            ];

            $dispositionParametersIndex = 9;

            if (isset($itemPart[$dispositionParametersIndex][0])) {
                $attribute = null;
                $dispositionParameters = [];
                $part->disposition = $itemPart[$dispositionParametersIndex][0];
                if (isset($itemPart[$dispositionParametersIndex][1]) && is_array($itemPart[$dispositionParametersIndex][1])) {
                    foreach ($itemPart[$dispositionParametersIndex][1] as $value) {
                        if (empty($attribute)) {
                            $attribute = [
                                'attribute' => $value,
                                'value' => null,
                            ];
                        } else {
                            $attribute['value'] = $value;
                            $dispositionParameters[] = (object) $attribute;
                            $attribute = null;
                        }
                    }
                }
                $part->dparameters = $dispositionParameters;
                $part->ifdparameters = 1;
                $part->ifdisposition = 1;
            } else {
                unset($part->disposition);
                unset($part->dparameters);
            }

            $message->parts[] = $part;
        }
        
        return $message;
    }

    protected static function extractParameters($attributes, $parameters)
    {
        if (empty($attributes)) {
            return [];
        }

        $attribute = null;

        foreach ($attributes as $value) {
            if (empty($attribute)) {
                $attribute = [
                    'attribute' => $value,
                    'value' => null,
                ];
            } else {
                $attribute['value'] = $value;
                $parameters[] = (object) $attribute;
                $attribute = null;
            }
        }

        return $parameters;
    }

    protected static function getEncoding($item, $encodingIndex)
    {
        return isset($item[$encodingIndex]) ? (self::$encodingNumber[$item[$encodingIndex]] ?? 0) : 0;
    }

    protected static function textStructure($structure)
    {
        $parameters = self::extractParameters($structure[2], []);

        return (object) [
            'type' => 0,
            'encoding' => self::getEncoding($structure, 5),
            'ifsubtype' => 1,
            'subtype' => $structure[1],
            'ifdescription' => 0,
            'ifid' => 0,
            'lines' => intval($structure[7]),
            'bytes' => intval($structure[6]),
            'ifdisposition' => 0,
            'ifdparameters' => 0,
            'ifparameters' => count($parameters),
            'parameters' => count($parameters) ? $parameters : (object) [],
        ];
    }
}
