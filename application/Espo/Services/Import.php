<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2020 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\BadRequest;

use Espo\ORM\Entity;
use Espo\Entities\User;

use StdClass;

use Espo\Core\Di;

use Espo\Core\Record\Collection as RecordCollection;

class Import extends \Espo\Services\Record implements

    Di\FileManagerAware,
    Di\FileStorageManagerAware
{
    use Di\FileManagerSetter;
    use Di\FileStorageManagerSetter;

    const REVERT_PERMANENTLY_REMOVE_PERIOD_DAYS = 2;

    protected $dateFormatsMap = [
        'YYYY-MM-DD' => 'Y-m-d',
        'DD-MM-YYYY' => 'd-m-Y',
        'MM-DD-YYYY' => 'm-d-Y',
        'MM/DD/YYYY' => 'm/d/Y',
        'DD/MM/YYYY' => 'd/m/Y',
        'DD.MM.YYYY' => 'd.m.Y',
        'MM.DD.YYYY' => 'm.d.Y',
        'YYYY.MM.DD' => 'Y.m.d',
    ];

    protected $timeFormatsMap = [
        'HH:mm' => 'H:i',
        'HH:mm:ss' => 'H:i:s',
        'hh:mm a' => 'h:i a',
        'hh:mma' => 'h:ia',
        'hh:mm A' => 'h:iA',
        'hh:mmA' => 'h:iA',
    ];

    protected function getFileStorageManager()
    {
        return $this->fileStorageManager;
    }

    protected function getFileManager()
    {
        return $this->fileManager;
    }

    protected function getAcl()
    {
        return $this->acl;
    }

    protected function getMetadata()
    {
        return $this->metadata;
    }

    protected function getServiceFactory()
    {
        return $this->serviceFactory;
    }

    public function loadAdditionalFields(Entity $entity)
    {
        parent::loadAdditionalFields($entity);

        $importedCount = $this->getRepository()->countRelated($entity, 'imported');
        $duplicateCount = $this->getRepository()->countRelated($entity, 'duplicates');
        $updatedCount = $this->getRepository()->countRelated($entity, 'updated');

        $entity->set([
            'importedCount' => $importedCount,
            'duplicateCount' => $duplicateCount,
            'updatedCount' => $updatedCount,
        ]);
    }

    public function findLinked(string $id, string $link, array $params) : RecordCollection
    {
        $entity = $this->getRepository()->get($id);
        $foreignEntityType = $entity->get('entityType');

        if (!$this->getAcl()->check($entity, 'read')) {
            throw new Forbidden();
        }
        if (!$this->getAcl()->check($foreignEntityType, 'read')) {
            throw new Forbidden();
        }

        $selectParams = $this->getSelectManager($foreignEntityType)->getSelectParams($params, true);

        if (array_key_exists($link, $this->linkSelectParams)) {
            $selectParams = array_merge($selectParams, $this->linkSelectParams[$link]);
        }

        $collection = $this->getRepository()->findRelated($entity, $link, $selectParams);

        $recordService = $this->recordServiceContainer->get($foreignEntityType);

        foreach ($collection as $e) {
            $recordService->loadAdditionalFieldsForList($e);
            $recordService->prepareEntityForOutput($e);
        }

        $total = $this->getRepository()->countRelated($entity, $link, $selectParams);

        return new RecordCollection($collection, $total);
    }

    public function uploadFile(string $contents) : string
    {
        $attachment = $this->getEntityManager()->getEntity('Attachment');
        $attachment->set('type', 'text/csv');
        $attachment->set('role', 'Import File');
        $attachment->set('name', 'import-file.csv');
        $attachment->set('contents', $contents);
        $this->getEntityManager()->saveEntity($attachment);

        return $attachment->id;
    }

    protected function readCsvString(&$string, $CSV_SEPARATOR = ';', $CSV_ENCLOSURE = '"', $CSV_LINEBREAK = "\n")
    {
        $o = [];

        $cnt = strlen($string);
        $esc = false;
        $escesc = false;

        $num = 0;
        $i = 0;
        while ($i < $cnt) {
            $s = $string[$i];
            if ($s == $CSV_LINEBREAK) {
                if ($esc) {
                    $o[$num].= $s;
                } else {
                    $i++;
                    break;
                }
            } else if ($s == $CSV_SEPARATOR) {
                if ($esc) {
                    $o[$num].= $s;
                } else {
                    $num++;
                    $esc = false;
                    $escesc = false;
                }
            } else if ($s == $CSV_ENCLOSURE) {
                if ($escesc) {
                    $o[$num].= $CSV_ENCLOSURE;
                    $escesc = false;
                }
                if ($esc) {
                    $esc = false;
                    $escesc = true;
                } else {
                    $esc = true;
                    $escesc = false;
                }
            } else {
                if (!array_key_exists($num, $o)) {
                    $o[$num] = '';
                }
                if ($escesc) {
                    $o[$num] .= $CSV_ENCLOSURE;
                    $escesc = false;
                }
                $o[$num] .= $s;
            }
            $i++;
        }
        $string = substr($string, $i);

        $keys = array_keys($o);
        $maxKey = end($keys);
        for ($i = 0; $i < $maxKey; $i++) {
            if (!array_key_exists($i, $o)) {
                $o[$i] = '';
            }
        }

        return $o;
    }

    public function revert(string $id)
    {
        $import = $this->entityManager->getEntity('Import', $id);
        if (empty($import)) {
            throw new NotFound("Could not find import record.");
        }

        if (!$this->getAcl()->check($import, 'delete')) {
            throw new Forbidden("No access import record.");
        }

        $importEntityList = $this->entityManager->getRepository('ImportEntity')
            ->sth()
            ->where([
                'importId' => $import->id,
                'isImported' => true,
            ])
            ->find();

        $removeFromDb = false;
        $createdAt = $import->get('createdAt');
        if ($createdAt) {
            $dtNow = new \DateTime();
            $createdAtDt = new \DateTime($createdAt);
            $dayDiff = ($dtNow->getTimestamp() - $createdAtDt->getTimestamp()) / 60 / 60 / 24;
            if ($dayDiff < self::REVERT_PERMANENTLY_REMOVE_PERIOD_DAYS) {
                $removeFromDb = true;
            }
        }

        foreach ($importEntityList as $importEntity) {
            $entityType = $importEntity->get('entityType');
            $entityId = $importEntity->get('entityId');

            if (!$entityType || !$entityId) {
                continue;
            }

            if (!$this->entityManager->hasRepository($entityType)) {
                continue;
            }

            $entity = $this->entityManager->getRepository($entityType)
                ->select(['id'])
                ->where(['id' => $entityId])
                ->findOne();

            if (!$entity) {
                continue;
            }

            $this->entityManager->removeEntity($entity, [
                'noStream' => true,
                'noNotifications' => true,
                'import' => true,
                'silent' => true,
            ]);

            if ($removeFromDb) {
                $this->entityManager->getRepository($entityType)->deleteFromDb($entityId);
            }
        }

        $this->getEntityManager()->removeEntity($import);

        $this->processActionHistoryRecord('delete', $import);

        return true;
    }

    public function removeDuplicates(string $id)
    {
        $import = $this->entityManager->getEntity('Import', $id);
        if (empty($import)) {
            throw new NotFound();
        }

        if (!$this->getAcl()->check($import, 'delete')) {
            throw new Forbidden();
        }

        $importEntityList = $this->entityManager->getRepository('ImportEntity')
            ->sth()
            ->where([
                'importId' => $import->id,
                'isDuplicate' => true,
            ])
            ->find();

        foreach ($importEntityList as $importEntity) {
            $entityType = $importEntity->get('entityType');
            $entityId = $importEntity->get('entityId');

            if (!$entityType || !$entityId) {
                continue;
            }

            if (!$this->entityManager->hasRepository($entityType)) {
                continue;
            }

            $entity = $this->entityManager->getRepository($entityType)
                ->select(['id'])
                ->where(['id' => $entityId])
                ->findOne();

            if (!$entity) {
                continue;
            }

            $this->entityManager->removeEntity($entity, [
                'noStream' => true,
                'noNotifications' => true,
                'import' => true,
                'silent' => true,
            ]);

            $this->entityManager->getRepository($entityType)->deleteFromDb($entityId);
        }
    }

    public function jobRunIdleImport(StdClass $data)
    {
        if (
            empty($data->userId) ||
            empty($data->userId) ||
            !isset($data->importAttributeList) ||
            !isset($data->params) ||
            !isset($data->entityType)
        ) {
            throw new Error("Import: Bad job data.");
        }

        $entityType = $data->entityType;
        $params = json_decode(json_encode($data->params), true);
        $attachmentId = $data->attachmentId;
        $importId = $data->importId;
        $importAttributeList = $data->importAttributeList;
        $userId = $data->userId;

        $user = $this->getEntityManager()->getEntity('User', $userId);

        if (!$user) {
            throw new Error("Import: User not found.");
        }
        if (!$user->get('isActive')) {
            throw new Error("Import: User is not active.");
        }

        $this->import($entityType, $importAttributeList, $attachmentId, $params, $importId, $user);
    }

    public function importById(string $id, bool $startFromLastIndex = false, bool $forceResume = false) : StdClass
    {
        $import = $this->getEntityManager()->getEntity('Import', $id);

        if (!$import) {
            throw new NotFound("Import '{$id}' not found.");
        }

        $status = $import->get('status');

        if ($status !== 'Standby') {
            if (in_array($status, ['In Process', 'Failed'])) {
                if (!$forceResume) {
                    throw new Forbidden("Import has '{$status}' status. Use -r flag to force resume.");
                }
            } else {
                throw new Forbidden("Can't run import with '{$status}' status.");
            }
        }

        $entityType = $import->get('entityType');
        $attributeList = $import->get('attributeList') ?? [];

        $params = $import->get('params') ?? (object) [];
        $params = json_decode(json_encode($params), true);

        $params['startFromLastIndex'] = $startFromLastIndex;

        return $this->import($entityType, $attributeList, $import->get('fileId'), $params, $id);
    }

    public function importFileWithParamsId(string $contents, string $importParamsId) : StdClass
    {
        if (!$contents) {
            throw new Error("File contents is empty.");
        }

        $source = $this->getEntityManager()->getEntity('Import', $importParamsId);

        if (!$source) {
            throw new Error("Import {$importParamsId} not found.");
        }

        $entityType = $source->get('entityType');
        $attributeList = $source->get('attributeList') ?? [];

        $params = $source->get('params') ?? (object) [];
        $params = json_decode(json_encode($params), true);

        unset($params['idleMode']);
        unset($params['manualMode']);

        $attachmentId = $this->uploadFile($contents);

        return $this->import($entityType, $attributeList, $attachmentId, $params);
    }

    /**
     * @param array $params [
     *    'delimiter' => (string),
     *    'textQualifier' => (string),
     *    'idleMode' => (bool),
     *    'manualMode' => (bool),
     *    'silentMode' => (bool),
     *    'headerRow' => (bool),
     *    'action' => (string),
     *    'skipDuplicateChecking' => (bool),
     *    'updateBy' => (array),
     *    'defaultValues' => (array|object),
     *    'textQualifier' => (string),
     *    'personNameFormat' => (string),
     *    'delimiter' => (string),
     *    'timeFormat' => (string),
     *    'currency' => (string),
     *    'timezone' => (string),
     *    'startFromLastIndex' => (bool),
     * ]
     * @return StdClass [
     *     id: (string),
     *     countCreated: (int),
     *     countUpdated: (int),
     * ]
     */
    public function import(
        string $scope, array $importAttributeList, string $attachmentId, array $params = [], ?string $importId = null,
        ?User $user = null
    ) : StdClass {
        $delimiter = ',';
        if (!empty($params['delimiter'])) {
            $delimiter = $params['delimiter'];
        }
        $enclosure = '"';
        if (!empty($params['textQualifier'])) {
            $enclosure = $params['textQualifier'];
        }

        $delimiter = str_replace('\t', "\t", $delimiter);

        if (!$user) {
            $user = $this->getUser();
        }

        if (!$user->isAdmin()) {
            $forbiddenAttrbuteList = $this->getAclManager()->getScopeForbiddenAttributeList($user, $scope, 'edit');
            foreach ($importAttributeList as $i => $attribute) {
                if (in_array($attribute, $forbiddenAttrbuteList)) {
                    unset($importAttributeList[$i]);
                }
            }

            if (!$this->getAclManager()->checkScope($user, $scope, 'create')) {
                throw new Error('Import: Create is forbidden.');
            }
        }

        $attachment = $this->getEntityManager()->getEntity('Attachment', $attachmentId);
        if (!$attachment) {
            throw new Error('Import error');
        }

        $contents = $this->getFileStorageManager()->getContents($attachment);
        if (empty($contents)) {
            throw new Error('Import error');
        }

        $startFromIndex = null;

        if ($importId) {
            $import = $this->getEntityManager()->getEntity('Import', $importId);
            if (!$import) {
                throw new Error('Import: Could not find import record.');
            }

            if ($params['startFromLastIndex'] ?? false) {
                $startFromIndex = $import->get('lastIndex');
            }

            $import->set('status', 'In Process');
        } else {
            $import = $this->getEntityManager()->getEntity('Import');
            $import->set([
                'entityType' => $scope,
                'fileId' => $attachmentId
            ]);

            $import->set('status', 'In Process');

            if ($params['manualMode'] ?? false) {
                unset($params['idleMode']);
                $import->set('status', 'Standby');
            } else if ($params['idleMode'] ?? false) {
                $import->set('status', 'Pending');
            }

            $import->set('params', $params);
            $import->set('attributeList', $importAttributeList);
        }

        $this->getEntityManager()->saveEntity($import);

        $this->processActionHistoryRecord('create', $import);

        if (!$importId && ($params['manualMode'] ?? false)) {
            return (object) [
                'id' => $import->id,
                'countCreated' => 0,
                'countUpdated' => 0,
                'manualMode' => true,
            ];
        }

        if (!empty($params['idleMode'])) {
            $params['idleMode'] = false;

            $job = $this->getEntityManager()->getEntity('Job');
            $job->set([
                'serviceName' => 'Import',
                'methodName' => 'jobRunIdleImport',
                'data' => [
                    'entityType' => $scope,
                    'params' => $params,
                    'attachmentId' => $attachmentId,
                    'importAttributeList' => $importAttributeList,
                    'importId' => $import->id,
                    'userId' => $this->getUser()->id
                ]
            ]);
            $this->getEntityManager()->saveEntity($job);

            return (object) [
                'id' => $import->id,
                'countCreated' => 0,
                'countUpdated' => 0
            ];
        }

        try {
            $result = (object) [
                'importedIds' => [],
                'updatedIds' => [],
                'duplicateIds' => [],
            ];

            $i = -1;

            $contents = str_replace("\r\n", "\n", $contents);

            while ($arr = $this->readCsvString($contents, $delimiter, $enclosure)) {
                $i++;

                if ($i == 0 && !empty($params['headerRow'])) {
                    continue;
                }

                if (count($arr) == 1 && empty($arr[0]) && count($importAttributeList) > 1) {
                    continue;
                }

                if (!is_null($startFromIndex) && $i <= $startFromIndex) {
                    continue;
                }

                $rowResult = $this->importRow($scope, $importAttributeList, $arr, $params, $user);

                if (!$rowResult) {
                    continue;
                }

                $import->set('lastIndex', $i);

                $this->getEntityManager()->saveEntity($import, [
                    'skipHooks' => true,
                    'silent' => true,
                ]);

                if ($rowResult->isImported ?? false) {
                    $result->importedIds[] = $rowResult->id;
                }
                if ($rowResult->isUpdated ?? false) {
                    $result->updatedIds[] = $rowResult->id;
                }
                if ($rowResult->isDuplicate ?? false) {
                    $result->duplicateIds[] = $rowResult->id;
                }

                $this->getEntityManager()->createEntity('ImportEntity', [
                    'entityType' => $scope,
                    'entityId' => $rowResult->id,
                    'importId' => $import->id,
                    'isImported' => $rowResult->isImported ?? false,
                    'isUpdated' => $rowResult->isUpdated ?? false,
                    'isDuplicate' => $rowResult->isDuplicate ?? false,
                ]);
            }
        } catch (\Exception $e) {
            $GLOBALS['log']->error('Import Error: '. $e->getMessage());
            $import->set('status', 'Failed');
        }

        $import->set('status', 'Complete');

        $this->getEntityManager()->saveEntity($import);

        return (object) [
            'id' => $import->id,
            'countCreated' => count($result->importedIds),
            'countUpdated' => count($result->updatedIds),
        ];
    }

    public function importRow(
        string $scope, array $importAttributeList, array $row, array $params = [], ?User $user = null
    ) : ?StdClass {
        $id = null;
        $action = 'create';
        if (!empty($params['action'])) {
            $action = $params['action'];
        }

        if (empty($importAttributeList)) {
            return null;
        }

        if (in_array($action, ['createAndUpdate', 'update'])) {
            $updateByAttributeList = [];
            $whereClause = [];
            if (!empty($params['updateBy']) && is_array($params['updateBy'])) {
                foreach ($params['updateBy'] as $i) {
                    if (array_key_exists($i, $importAttributeList)) {
                        $updateByAttributeList[] = $importAttributeList[$i];
                        $whereClause[$importAttributeList[$i]] = $row[$i];
                    }
                }
            }
        }

        $recordService = $this->recordServiceContainer->get($scope);

        if (in_array($action, ['createAndUpdate', 'update'])) {
            if (!count($updateByAttributeList)) {
                return null;
            }
            $entity = $this->getEntityManager()->getRepository($scope)->where($whereClause)->findOne();

            if ($entity) {
                if (!$user->isAdmin()) {
                    if (!$this->getAclManager()->checkEntity($user, $entity, 'edit')) {
                        return null;
                    }
                }
            }
            if (!$entity) {
                if ($action == 'createAndUpdate') {
                    $entity = $this->getEntityManager()->getEntity($scope);
                    if (array_key_exists('id', $whereClause)) {
                        $entity->set('id', $whereClause['id']);
                    }
                } else {
                    return null;
                }
            }
        } else {
            $entity = $this->getEntityManager()->getEntity($scope);
        }

        $isNew = $entity->isNew();

        if (!empty($params['defaultValues'])) {
            if (is_object($params['defaultValues'])) {
                $v = get_object_vars($params['defaultValues']);
            } else {
                $v = $params['defaultValues'];
            }
            $entity->set($v);
        }

        $attributeDefs = $entity->getAttributes();
        $relDefs = $entity->getRelations();

        $phoneFieldList = [];
        if (
            $entity->hasAttribute('phoneNumber')
            &&
            $entity->getAttributeParam('phoneNumber', 'fieldType') === 'phone'
        ) {
            $typeList = $this->getMetadata()->get('entityDefs.' . $scope . '.fields.phoneNumber.typeList', []);
            foreach ($typeList as $type) {
                $attr = str_replace(' ', '_', ucfirst($type));
                $phoneFieldList[] = 'phoneNumber' . $attr;
            }
        }

        $valueMap = (object) [];
        foreach ($importAttributeList as $i => $attribute) {
            if (!empty($attribute)) {
                if (!array_key_exists($i, $row)) {
                    continue;
                }
                $value = $row[$i];
                $valueMap->$attribute = $value;
            }
        }

        foreach ($importAttributeList as $i => $attribute) {
            if (!empty($attribute)) {
                if (!array_key_exists($i, $row)) {
                    continue;
                }
                $value = $row[$i];
                if ($attribute == 'id') {
                    if ($params['action'] == 'create') {
                        $entity->id = $value;
                    }
                    continue;
                }
                if (array_key_exists($attribute, $attributeDefs)) {
                    $attributeType = $entity->getAttributeType($attribute);

                    if ($value !== '') {
                        $type = $this->getMetadata()->get(['entityDefs', $scope, 'fields', $attribute, 'type']);

                        if ($attribute === 'emailAddress' && $type === 'email') {
                            $emailAddressData = $entity->get('emailAddressData');
                            $emailAddressData = $emailAddressData ?? [];
                            $o = (object) [
                                'emailAddress' => $value,
                                'primary' => true,
                            ];
                            $emailAddressData[] = $o;
                            $entity->set('emailAddressData', $emailAddressData);
                            continue;
                        }

                        if ($attribute === 'phoneNumber' && $type === 'phone') {
                            $phoneNumberData = $entity->get('phoneNumberData');
                            $phoneNumberData = $phoneNumberData ?? [];
                            $o = (object) [
                                'phoneNumber' => $value,
                                'primary' => true,
                            ];
                            $phoneNumberData[] = $o;
                            $entity->set('phoneNumberData', $phoneNumberData);
                            continue;
                        }

                        if ($type == 'personName') {
                            $firstNameAttribute = 'first' . ucfirst($attribute);
                            $lastNameAttribute = 'last' . ucfirst($attribute);
                            $middleNameAttribute = 'middle' . ucfirst($attribute);

                            $personNameData = $this->parsePersonName($value, $params['personNameFormat']);

                            if (!$entity->get($firstNameAttribute) && isset($personNameData['firstName'])) {
                                $personNameData['firstName'] = $this->prepareAttributeValue(
                                    $entity, $firstNameAttribute, $personNameData['firstName']
                                );
                                $entity->set($firstNameAttribute, $personNameData['firstName']);
                            }
                            if (!$entity->get($lastNameAttribute)) {
                                $personNameData['lastName'] = $this->prepareAttributeValue(
                                    $entity, $lastNameAttribute, $personNameData['lastName']
                                );
                                $entity->set($lastNameAttribute, $personNameData['lastName']);
                            }
                            if (!$entity->get($middleNameAttribute) && isset($personNameData['middleName'])) {
                                $personNameData['middleName'] = $this->prepareAttributeValue(
                                    $entity, $middleNameAttribute, $personNameData['middleName']
                                );
                                $entity->set($middleNameAttribute, $personNameData['middleName']);
                            }
                            continue;
                        }
                    }

                    if (
                        $value === '' &&
                        !in_array($attributeType, [Entity::BOOL])
                    ) {
                        continue;
                    }

                    $entity->set($attribute, $this->parseValue($entity, $attribute, $value, $params));

                } else {
                    if (in_array($attribute, $phoneFieldList) && !empty($value)) {
                        $phoneNumberData = $entity->get('phoneNumberData');
                        $isPrimary = false;
                        if (empty($phoneNumberData)) {
                            $phoneNumberData = [];
                            if (empty($valueMap->phoneNumber)) $isPrimary = true;
                        }
                        $type = str_replace('phoneNumber', '', $attribute);
                        $type = str_replace('_', ' ', $type);
                        $o = (object) [
                            'phoneNumber' => $value,
                            'type' => $type,
                            'primary' => $isPrimary,
                        ];
                        $phoneNumberData[] = $o;
                        $entity->set('phoneNumberData', $phoneNumberData);
                    }

                    if (
                        strpos($attribute, 'emailAddress') === 0 && $attribute !== 'emailAddress'
                        &&
                        $entity->hasAttribute('emailAddress')
                        &&
                        $entity->hasAttribute('emailAddressData')
                        &&
                        is_numeric(substr($attribute, 12))
                        &&
                        intval(substr($attribute, 12)) >= 2
                        &&
                        intval(substr($attribute, 12)) <= 4
                        &&
                        !empty($value)
                    ) {
                        $emailAddressData = $entity->get('emailAddressData');
                        $isPrimary = false;
                        if (empty($emailAddressData)) {
                            $emailAddressData = [];
                            if (empty($valueMap->emailAddress)) $isPrimary = true;
                        }
                        $o = (object) [
                            'emailAddress' => $value,
                            'primary' => $isPrimary,
                        ];
                        $emailAddressData[] = $o;
                        $entity->set('emailAddressData', $emailAddressData);
                    }
                }
            }
        }

        $defaultCurrency = $this->getConfig('defaultCurrency');
        if (!empty($params['currency'])) {
            $defaultCurrency = $params['currency'];
        }

        $mFieldsDefs = $this->getMetadata()->get(['entityDefs', $entity->getEntityType(), 'fields'], []);

        foreach ($mFieldsDefs as $field => $defs) {
            if (!empty($defs['type']) && $defs['type'] === 'currency') {
                if ($entity->has($field) && !$entity->get($field . 'Currency')) {
                    $entity->set($field . 'Currency', $defaultCurrency);
                }
            }
        }

        foreach ($importAttributeList as $i => $attribute) {
            if (!array_key_exists($attribute, $attributeDefs)) continue;;
            $defs = $attributeDefs[$attribute];
            $type = $attributeDefs[$attribute]['type'];

            if (in_array($type, [Entity::FOREIGN, Entity::VARCHAR]) && !empty($defs['foreign'])) {
                $relatedEntityIsPerson = is_array($defs['foreign']) &&
                    in_array('firstName', $defs['foreign']) && in_array('lastName', $defs['foreign']);

                if ($defs['foreign'] === 'name' || $relatedEntityIsPerson) {
                    if ($entity->has($attribute)) {
                        $relation = $defs['relation'];
                        if ($attribute == $relation . 'Name' && !$entity->has($relation . 'Id') && array_key_exists($relation, $relDefs)) {
                            if ($relDefs[$relation]['type'] == Entity::BELONGS_TO) {
                                $value = $entity->get($attribute);
                                $scope = $relDefs[$relation]['entity'];

                                if ($relatedEntityIsPerson) {
                                    $where = $this->parsePersonName($value, $params['personNameFormat']);
                                } else {
                                    $where['name'] = $value;
                                }

                                $found = $this->getEntityManager()->getRepository($scope)->where($where)->findOne();

                                if ($found) {
                                    $entity->set($relation . 'Id', $found->id);
                                    $entity->set($relation . 'Name', $found->get('name'));
                                } else {
                                    if (!in_array($scope, ['User', 'Team'])) {
                                        // TODO create related record with name $name and relate
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $result = [];

        try {
            if ($isNew) {
                $isDuplicate = false;
                if (empty($params['skipDuplicateChecking'])) {
                    $isDuplicate = $recordService->checkIsDuplicate($entity);
                }
            }
            if ($entity->id) {
                $sql = $this->getEntityManager()->getRepository($entity->getEntityType())->deleteFromDb($entity->id, true);
            }
            $this->getEntityManager()->saveEntity($entity, [
                'noStream' => true,
                'noNotifications' => true,
                'import' => true,
                'silent' => $params['silentMode'] ?? false,
            ]);

            $result['id'] = $entity->id;

            if ($isNew) {
                $result['isImported'] = true;
                if ($isDuplicate) {
                    $result['isDuplicate'] = true;
                }
            } else {
                $result['isUpdated'] = true;
            }

        } catch (\Exception $e) {
            $GLOBALS['log']->error('Import: [' . $e->getCode() . '] ' .$e->getMessage());
        }

        return (object) $result;
    }

    protected function prepareAttributeValue($entity, $attribute, $value)
    {
        if ($entity->getAttributeType($attribute) === $entity::VARCHAR) {
            $maxLength = $entity->getAttributeParam($attribute, 'len');
            if ($maxLength) {
                if (mb_strlen($value) > $maxLength) {
                    $value = substr($value, 0, $maxLength);
                }
            }
        }
        return $value;
    }

    protected function parsePersonName($value, $format)
    {
        $firstName = null;
        $lastName = $value;

        $middleName = null;

        switch ($format) {
            case 'f l':
                $pos = strpos($value, ' ');
                if ($pos) {
                    $firstName = trim(substr($value, 0, $pos));
                    $lastName = trim(substr($value, $pos + 1));
                }
                break;
            case 'l f':
                $pos = strpos($value, ' ');
                if ($pos) {
                    $lastName = trim(substr($value, 0, $pos));
                    $firstName = trim(substr($value, $pos + 1));
                }
                break;
            case 'l, f':
                $pos = strpos($value, ',');
                if ($pos) {
                    $lastName = trim(substr($value, 0, $pos));
                    $firstName = trim(substr($value, $pos + 1));
                }
                break;

            case 'f m l':
                $pos = strpos($value, ' ');
                if ($pos) {
                    $firstName = trim(substr($value, 0, $pos));
                    $lastName = trim(substr($value, $pos + 1));

                    $value = $lastName;

                    $pos = strpos($value, ' ');
                    if ($pos) {
                        $middleName = trim(substr($value, 0, $pos));
                        $lastName = trim(substr($value, $pos + 1));

                        return [
                            'firstName' => $firstName,
                            'middleName' => $middleName,
                            'lastName' => $lastName,
                        ];
                    }
                }
                break;

            case 'l f m':
                $pos = strpos($value, ' ');
                if ($pos) {
                    $lastName = trim(substr($value, 0, $pos));
                    $firstName = trim(substr($value, $pos + 1));

                    $value = $firstName;

                    $pos = strpos($value, ' ');
                    if ($pos) {
                        $firstName = trim(substr($value, 0, $pos));
                        $middleName = trim(substr($value, $pos + 1));

                        return [
                            'firstName' => $firstName,
                            'middleName' => $middleName,
                            'lastName' => $lastName,
                        ];
                    }
                }
                break;
        }
        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
        ];
    }

    protected function parseValue(Entity $entity, $attribute, $value, $params = [])
    {
        $decimalMark = '.';
        if (!empty($params['decimalMark'])) {
            $decimalMark = $params['decimalMark'];
        }

        $dateFormat = 'Y-m-d';
        if (!empty($params['dateFormat'])) {
            if (!empty($this->dateFormatsMap[$params['dateFormat']])) {
                $dateFormat = $this->dateFormatsMap[$params['dateFormat']];
            }
        }

        $timeFormat = 'H:i';
        if (!empty($params['timeFormat'])) {
            if (!empty($this->timeFormatsMap[$params['timeFormat']])) {
                $timeFormat = $this->timeFormatsMap[$params['timeFormat']];
            }
        }

        $type = $entity->getAttributeType($attribute);

        switch ($type) {
            case Entity::DATE:
                $dt = \DateTime::createFromFormat($dateFormat, $value);
                if ($dt) {
                    return $dt->format('Y-m-d');
                }
                return null;

            case Entity::DATETIME:
                $timezone = new \DateTimeZone(isset($params['timezone']) ? $params['timezone'] : 'UTC');
                $dt = \DateTime::createFromFormat($dateFormat . ' ' . $timeFormat, $value, $timezone);
                if ($dt) {
                    $dt->setTimezone(new \DateTimeZone('UTC'));
                    return $dt->format('Y-m-d H:i:s');
                }
                return null;

            case Entity::FLOAT:
                $a = explode($decimalMark, $value);
                $a[0] = preg_replace('/[^A-Za-z0-9\-]/', '', $a[0]);

                if (count($a) > 1) {
                    return floatval($a[0] . '.' . $a[1]);
                } else {
                    return floatval($a[0]);
                }
            case Entity::INT:
                return intval($value);

            case Entity::BOOL:
                if ($value && strtolower($value) !== 'false' && $value !== '0') {
                    return true;
                }
                return false;

            case Entity::JSON_OBJECT:
                $value = \Espo\Core\Utils\Json::decode($value);
                return $value;

            case Entity::JSON_ARRAY:
                if (!is_string($value)) return null;
                if (!strlen($value)) return null;
                if ($value[0] === '[') {
                    $value = \Espo\Core\Utils\Json::decode($value);
                    return $value;
                } else {
                    $value = explode(',', $value);
                    return $value;
                }
        }

        $value = $this->prepareAttributeValue($entity, $attribute, $value);

        return $value;
    }

    public function unmarkAsDuplicate(string $importId, string $entityType, string $entityId)
    {
        $e = $this->getEntityManager()->getRepository('ImportEntity')
            ->where([
                'importId' => $importId,
                'entityType' => $entityType,
                'entityId' => $entityId,
            ])
            ->findOne();

        if (!$e) {
            throw new NotFound();
        }

        $e->set('isDuplicate', false);

        $this->getEntityManager()->saveEntity($e);
    }
}
