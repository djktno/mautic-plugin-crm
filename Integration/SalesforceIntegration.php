<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticCrmBundle\Integration;

use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Model\SubmissionModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\IntegrationEntity;
use Mautic\PluginBundle\Entity\IntegrationEntityRepository;
use MauticPlugin\MauticCrmBundle\Api\SalesforceApi;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class SalesforceIntegration.
 */
class SalesforceIntegration extends CrmAbstractIntegration
{
    private $objects = [
        'Lead',
        'Contact',
        'Account',
    ];

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName()
    {
        return 'Salesforce';
    }

    /**
     * Get the array key for clientId.
     *
     * @return string
     */
    public function getClientIdKey()
    {
        return 'client_id';
    }

    /**
     * Get the array key for client secret.
     *
     * @return string
     */
    public function getClientSecretKey()
    {
        return 'client_secret';
    }

    /**
     * Get the array key for the auth token.
     *
     * @return string
     */
    public function getAuthTokenKey()
    {
        return 'access_token';
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getRequiredKeyFields()
    {
        return [
            'client_id'     => 'mautic.integration.keyfield.consumerid',
            'client_secret' => 'mautic.integration.keyfield.consumersecret',
        ];
    }

    /**
     * Get the keys for the refresh token and expiry.
     *
     * @return array
     */
    public function getRefreshTokenKeys()
    {
        return ['refresh_token', ''];
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAccessTokenUrl()
    {
        $config = $this->mergeConfigToFeatureSettings([]);

        if (isset($config['sandbox'][0]) and $config['sandbox'][0] === 'sandbox') {
            return 'https://test.salesforce.com/services/oauth2/token';
        }

        return 'https://login.salesforce.com/services/oauth2/token';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationUrl()
    {
        $config = $this->mergeConfigToFeatureSettings([]);

        if (isset($config['sandbox'][0]) and $config['sandbox'][0] === 'sandbox') {
            return 'https://test.salesforce.com/services/oauth2/authorize';
        }

        return 'https://login.salesforce.com/services/oauth2/authorize';
    }

    /**
     * @return string
     */
    public function getAuthScope()
    {
        return 'api refresh_token';
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        return sprintf('%s/services/data/v34.0/sobjects', $this->keys['instance_url']);
    }

    /**
     * @return string
     */
    public function getQueryUrl()
    {
        return sprintf('%s/services/data/v34.0', $this->keys['instance_url']);
    }

    /**
     * @return string
     */
    public function getCompositeUrl()
    {
        return sprintf('%s/services/data/v38.0', $this->keys['instance_url']);
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $inAuthorization
     */
    public function getBearerToken($inAuthorization = false)
    {
        if (!$inAuthorization) {
            return $this->keys[$this->getAuthTokenKey()];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getAuthenticationType()
    {
        return 'oauth2';
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function getDataPriority()
    {
        return true;
    }

    /**
     * Get available company fields for choices in the config UI.
     *
     * @param array $settings
     *
     * @return array
     */
    public function getFormCompanyFields($settings = [])
    {
        return $this->getFormFieldsByObject('company', $settings);
    }

    /**
     * @param array $settings
     *
     * @return array|mixed
     *
     * @throws \Exception
     */
    public function getFormLeadFields($settings = [])
    {
        $leadFields    = $this->getFormFieldsByObject('Lead', $settings);
        $contactFields = $this->getFormFieldsByObject('Contact', $settings);

        return array_merge($leadFields, $contactFields);
    }

    /**
     * @param array $settings
     *
     * @return array|mixed
     *
     * @throws \Exception
     */
    public function getAvailableLeadFields($settings = [])
    {
        $silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;
        $salesForceObjects = [];

        if (isset($settings['feature_settings']['objects'])) {
            $salesForceObjects = $settings['feature_settings']['objects'];
        } else {
            $salesForceObjects[] = 'Lead';
        }

        $isRequired = function (array $field, $object) {
            return ($field['type'] !== 'boolean' && empty($field['nillable']) && !in_array($field['name'], ['Status', 'Id']))
                || ($object == 'Lead'
                    && in_array($field['name'], ['Company']));
        };

        $salesFields = [];
        try {
            if (!empty($salesForceObjects) and is_array($salesForceObjects)) {
                foreach ($salesForceObjects as $key => $sfObject) {
                    if ('Account' === $sfObject) {
                        // Match SF object to Mautic's
                        $sfObject = 'company';
                    }

                    if ($this->isAuthorized()) {
                        if (isset($sfObject) and $sfObject == 'Activity') {
                            continue;
                        }

                        $sfObject = trim($sfObject);

                        // Check the cache first
                        $settings['cache_suffix'] = $cacheSuffix = '.'.$sfObject;
                        if ($fields = parent::getAvailableLeadFields($settings)) {
                            $salesFields[$sfObject] = $fields;

                            continue;
                        }

                        if (!isset($salesFields[$sfObject])) {
                            $fields = $this->getApiHelper()->getLeadFields($sfObject);

                            if (!empty($fields['fields'])) {
                                foreach ($fields['fields'] as $fieldInfo) {
                                    if ((!$fieldInfo['updateable'] && (!$fieldInfo['calculated'] && $fieldInfo['name'] != 'Id'))
                                        || !isset($fieldInfo['name'])
                                        || in_array(
                                            $fieldInfo['type'],
                                            ['reference']
                                        )
                                    ) {
                                        continue;
                                    }
                                    if ($fieldInfo['type'] == 'boolean') {
                                        $type = 'boolean';
                                    } else {
                                        $type = 'string';
                                    }
                                    if ($sfObject !== 'company') {
                                        $salesFields[$sfObject][$fieldInfo['name'].'__'.$sfObject] = [
                                            'type'        => $type,
                                            'label'       => $sfObject.'-'.$fieldInfo['label'],
                                            'required'    => $isRequired($fieldInfo, $sfObject),
                                            'group'       => $sfObject,
                                            'optionLabel' => $fieldInfo['label'],
                                        ];
                                    } else {
                                        $salesFields[$sfObject][$fieldInfo['name']] = [
                                            'type'     => $type,
                                            'label'    => $fieldInfo['label'],
                                            'required' => $isRequired($fieldInfo, $sfObject),
                                        ];
                                    }
                                }

                                $this->cache->set('leadFields'.$cacheSuffix, $salesFields[$sfObject]);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);

            if (!$silenceExceptions) {
                throw $e;
            }
        }

        return $salesFields;
    }

    /**
     * {@inheritdoc}
     *
     * @param $section
     *
     * @return string
     */
    public function getFormNotes($section)
    {
        if ($section == 'authorization') {
            return ['mautic.salesforce.form.oauth_requirements', 'warning'];
        }

        return parent::getFormNotes($section);
    }

    /**
     * @param $params
     *
     * @return mixed
     */
    public function getFetchQuery($params)
    {
        $dateRange = $params;

        return $dateRange;
    }

    /**
     * Amend mapped lead data before creating to Mautic.
     *
     * @param array  $data
     * @param string $object
     *
     * @return int
     */
    public function amendLeadDataBeforeMauticPopulate($data, $object)
    {
        $settings['feature_settings']['objects'][] = $object;
        $fields                                    = array_keys($this->getAvailableLeadFields($settings));
        $params['fields']                          = implode(',', $fields);

        $count  = 0;
        $entity = null;

        if (isset($data['records']) and $object !== 'Activity') {
            foreach ($data['records'] as $record) {
                $integrationEntities = [];
                if (isset($record['attributes']['type']) && $record['attributes']['type'] == 'Account') {
                    $newName = '';
                } else {
                    $newName = '__'.$object;
                }
                foreach ($record as $key => $item) {
                    if ($object !== 'Activity') {
                        $dataObject[$key.$newName] = $item;
                    }
                }

                if (isset($dataObject) && $dataObject) {
                    if ($object == 'Lead' or $object == 'Contact') {
                        // Set owner so that it maps if configured to do so
                        if (!empty($dataObject['Owner__Lead']['Email'])) {
                            $dataObject['owner_email'] = $dataObject['Owner__Lead']['Email'];
                        } elseif (!empty($dataObject['Owner__Contact']['Email'])) {
                            $dataObject['owner_email'] = $dataObject['Owner__Contact']['Email'];
                        }
                        $entity                = $this->getMauticLead($dataObject, true, null, null);
                        $mauticObjectReference = 'lead';
                    } elseif ($object == 'Account') {
                        $entity                = $this->getMauticCompany($dataObject, true, null);
                        $mauticObjectReference = 'company';
                    } else {
                        $this->logIntegrationError(
                            new \Exception(
                                sprintf('Received an unexpected object without an internalObjectReference "%s"', $object)
                            )
                        );
                        continue;
                    }

                    if ($entity) {
                        /** @var IntegrationEntityRepository $integrationEntityRepo */
                        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
                        $integrationId         = $integrationEntityRepo->getIntegrationsEntityId(
                            'Salesforce',
                            $object,
                            $mauticObjectReference,
                            $entity->getId()
                        );

                        if ($integrationId == null) {
                            $integrationEntity = new IntegrationEntity();
                            $integrationEntity->setDateAdded(new \DateTime());
                            $integrationEntity->setIntegration('Salesforce');
                            $integrationEntity->setIntegrationEntity($object);
                            $integrationEntity->setIntegrationEntityId($record['Id']);
                            $integrationEntity->setInternalEntity($mauticObjectReference);
                            $integrationEntity->setInternalEntityId($entity->getId());
                            $integrationEntities[] = $integrationEntity;
                        } else {
                            $integrationEntity = $integrationEntityRepo->getEntity($integrationId[0]['id']);
                            $integrationEntity->setLastSyncDate(new \DateTime());
                            $integrationEntities[] = $integrationEntity;
                        }
                    } else {
                        continue;
                    }
                    ++$count;
                }

                $this->em->getRepository('MauticPluginBundle:IntegrationEntity')->saveEntities($integrationEntities);
                $this->em->clear('Mautic\PluginBundle\Entity\IntegrationEntity');

                unset($data);
            }
        }

        return $count;
    }

    /**
     * @param FormBuilder $builder
     * @param array       $data
     * @param string      $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ($formArea == 'features') {
            $builder->add(
                'sandbox',
                'choice',
                [
                    'choices' => [
                        'sandbox' => 'mautic.salesforce.sandbox',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.salesforce.form.sandbox',
                    'label_attr'  => ['class' => 'control-label'],
                    'empty_value' => false,
                    'required'    => false,
                    'attr'        => [
                        'onclick' => 'Mautic.postForm(mQuery(\'form[name="integration_details"]\'),\'\');',
                    ],
                ]
            );

            $builder->add(
                'updateOwner',
                'choice',
                [
                    'choices' => [
                        'updateOwner' => 'mautic.salesforce.updateOwner',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.salesforce.form.updateOwner',
                    'label_attr'  => ['class' => 'control-label'],
                    'empty_value' => false,
                    'required'    => false,
                    'attr'        => [
                        'onclick' => 'Mautic.postForm(mQuery(\'form[name="integration_details"]\'),\'\');',
                    ],
                ]
            );

            $builder->add(
                'objects',
                'choice',
                [
                    'choices' => [
                        'Lead'     => 'mautic.salesforce.object.lead',
                        'Contact'  => 'mautic.salesforce.object.contact',
                        'company'  => 'mautic.salesforce.object.company',
                        'Activity' => 'mautic.salesforce.object.activity',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'mautic.salesforce.form.objects_to_pull_from',
                    'label_attr'  => ['class' => ''],
                    'empty_value' => false,
                    'required'    => false,
                ]
            );

            $builder->add(
                'namespace',
                'text',
                [
                    'label'      => 'mautic.salesforce.form.namespace_prefix',
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => ['class' => 'form-control'],
                    'required'   => false,
                ]
            );
        }
    }

    /**
     * @param array  $fields
     * @param array  $keys
     * @param string $object
     *
     * @return array
     */
    public function cleanSalesForceData($fields, $keys, $object)
    {
        $leadFields = [];

        foreach ($keys as $key) {
            if (strstr($key, '__'.$object)) {
                $newKey              = str_replace('__'.$object, '', $key);
                $leadFields[$newKey] = $fields['leadFields'][$key];
            }
        }

        return $leadFields;
    }

    /**
     * @param \Mautic\LeadBundle\Entity\Lead $lead
     * @param array                          $config
     *
     * @return array|bool
     */
    public function pushLead($lead, $config = [])
    {
        $config = $this->mergeConfigToFeatureSettings($config);

        if (empty($config['leadFields'])) {
            return [];
        }

        $object = 'Lead'; //Salesforce objects, default is Lead

        $fields = array_keys($config['leadFields']);

        $leadFields         = $this->cleanSalesForceData($config, $fields, $object);
        $fieldsToUpdateInSf = isset($config['update_mautic']) ? array_keys($config['update_mautic'], 1) : [];
        $leadFields         = array_diff_key($leadFields, array_flip($fieldsToUpdateInSf));

        $mappedData[$object] = $this->populateLeadData($lead, ['leadFields' => $leadFields, 'object' => $object]);

        $this->amendLeadDataBeforePush($mappedData[$object]);

        if (empty($mappedData[$object])) {
            return false;
        }

        try {
            if ($this->isAuthorized()) {
                $createdLeadData = $this->getApiHelper()->createLead($mappedData[$object], $lead);
                if (isset($createdLeadData['id'])) {
                    /** @var IntegrationEntityRepository $integrationEntityRepo */
                    $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
                    $integrationId         = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', $object, 'leads', $lead->getId());

                    if (empty($integrationId)) {
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity->setDateAdded(new \DateTime());
                        $integrationEntity->setIntegration('Salesforce');
                        $integrationEntity->setIntegrationEntity($object);
                        $integrationEntity->setIntegrationEntityId($createdLeadData['id']);
                        $integrationEntity->setInternalEntity('lead');
                        $integrationEntity->setInternalEntityId($lead->getId());
                    } else {
                        $integrationEntity = $integrationEntityRepo->getEntity($integrationId[0]['id']);
                    }
                    $integrationEntity->setLastSyncDate(new \DateTime());
                    $this->em->persist($integrationEntity);
                    $this->em->flush($integrationEntity);
                }

                return $createdLeadData['id'];
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
        }

        return false;
    }

    /**
     * @param array      $params
     * @param null|array $query
     *
     * @return int|null
     */
    public function getLeads($params = [], $query = null)
    {
        $executed = null;

        $config = $this->mergeConfigToFeatureSettings([]);

        $salesForceObjects[] = 'Lead';

        if (isset($config['objects'])) {
            $salesForceObjects = $config['objects'];
        }

        if (!$query) {
            $query = $this->getFetchQuery($params);
        }

        try {
            if ($this->isAuthorized()) {
                foreach ($salesForceObjects as $object) {
                    if ($object !== 'Activity' and $object !== 'company') {
                        $result = $this->getApiHelper()->getLeads($query, $object);
                        $executed += $this->amendLeadDataBeforeMauticPopulate($result, $object);
                        if (isset($result['nextRecordsUrl'])) {
                            $query = $result['nextRecordsUrl'];
                            $this->getLeads($params, $query);
                        }
                    }
                }

                return $executed;
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
        }

        return $executed;
    }

    /**
     * @param array      $params
     * @param null|array $query
     *
     * @return int|null
     */
    public function getCompanies($params = [], $query = null)
    {
        $executed = null;

        $salesForceObject = 'Account';

        if (empty($query)) {
            $query = $this->getFetchQuery($params);
        }

        try {
            if ($this->isAuthorized()) {
                $result = $this->getApiHelper()->getLeads($query, $salesForceObject);
                $executed += $this->amendLeadDataBeforeMauticPopulate($result, $salesForceObject);
                if (isset($result['nextRecordsUrl'])) {
                    $query = $result['nextRecordsUrl'];
                    $this->getCompanies($params, $query);
                }

                return $executed;
            }
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
        }

        return $executed;
    }

    /**
     * @param array $params
     *
     * @return int|null
     *
     * @throws \Exception
     */
    public function pushLeadActivity($params = [])
    {
        $executed = null;

        $query  = $this->getFetchQuery($params);
        $config = $this->mergeConfigToFeatureSettings([]);

        /** @var SalesforceApi $apiHelper */
        $apiHelper = $this->getApiHelper();

        $salesForceObjects[] = 'Lead';
        if (isset($config['objects'])) {
            $salesForceObjects = $config['objects'];
        }

        /** @var IntegrationEntityRepository $integrationEntityRepo */
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $startDate             = new \DateTime($query['start']);
        $endDate               = new \DateTime($query['end']);
        $limit                 = 100;

        foreach ($salesForceObjects as $object) {
            try {
                if ($this->isAuthorized()) {
                    // Get first batch
                    $start         = 0;
                    $salesForceIds = $integrationEntityRepo->getIntegrationsEntityId(
                        'Salesforce',
                        $object,
                        'lead',
                        null,
                        $startDate->format('Y-m-d H:m:s'),
                        $endDate->format('Y-m-d H:m:s'),
                        true,
                        $start,
                        $limit
                    );

                    while (!empty($salesForceIds)) {
                        $executed += count($salesForceIds);

                        // Extract a list of lead Ids
                        $leadIds = [];
                        foreach ($salesForceIds as $ids) {
                            $leadIds[] = $ids['internal_entity_id'];
                        }

                        // Collect lead activity for this batch
                        $leadActivity = $this->getLeadData(
                            $startDate,
                            $endDate,
                            $leadIds
                        );

                        $salesForceLeadData = [];
                        foreach ($salesForceIds as $ids) {
                            $leadId = $ids['internal_entity_id'];
                            if (isset($leadActivity[$leadId])) {
                                $sfId                                 = $ids['integration_entity_id'];
                                $salesForceLeadData[$sfId]            = $leadActivity[$leadId];
                                $salesForceLeadData[$sfId]['id']      = $ids['integration_entity_id'];
                                $salesForceLeadData[$sfId]['leadId']  = $ids['internal_entity_id'];
                                $salesForceLeadData[$sfId]['leadUrl'] = $this->factory->getRouter()->generate(
                                    'mautic_plugin_timeline_view',
                                    ['integration' => 'Salesforce', 'leadId' => $leadId],
                                    UrlGeneratorInterface::ABSOLUTE_URL
                                );
                            }
                        }

                        if (!empty($salesForceLeadData)) {
                            $apiHelper->createLeadActivity($salesForceLeadData, $object);
                        }

                        // Get the next batch
                        $start += $limit;
                        $salesForceIds = $integrationEntityRepo->getIntegrationsEntityId(
                            'Salesforce',
                            $object,
                            'lead',
                            null,
                            $startDate->format('Y-m-d H:m:s'),
                            $endDate->format('Y-m-d H:m:s'),
                            true,
                            $start,
                            $limit
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->logIntegrationError($e);
            }
        }

        return $executed;
    }

    /**
     * @param \DateTime|null $startDate
     * @param \DateTime|null $endDate
     * @param                $leadId
     *
     * @return array
     */
    public function getLeadData(\DateTime $startDate = null, \DateTime $endDate = null, $leadId)
    {
        $leadIds = (!is_array($leadId)) ? [$leadId] : $leadId;

        $leadActivity = [];
        $options      = ['leadIds' => $leadIds, 'basic_select' => true, 'fromDate' => $startDate, 'toDate' => $endDate];

        /** @var LeadModel $leadModel */
        $leadModel      = $this->factory->getModel('lead');
        $pointsRepo     = $leadModel->getPointLogRepository();
        $results        = $pointsRepo->getLeadTimelineEvents(null, $options);
        $pointChangeLog = [];
        foreach ($results as $result) {
            if (!isset($pointChangeLog[$result['lead_id']])) {
                $pointChangeLog[$result['lead_id']] = [];
            }
            $pointChangeLog[$result['lead_id']][] = $result;
        }
        unset($results);

        /** @var EmailModel $emailModel */
        $emailModel = $this->factory->getModel('email');
        $emailRepo  = $emailModel->getStatRepository();
        $results    = $emailRepo->getLeadStats(null, $options);
        $emailStats = [];
        foreach ($results as $result) {
            if (!isset($emailStats[$result['lead_id']])) {
                $emailStats[$result['lead_id']] = [];
            }
            $emailStats[$result['lead_id']][] = $result;
        }
        unset($results);

        /** @var SubmissionModel $formSubmissionModel */
        $formSubmissionModel = $this->factory->getModel('form.submission');
        $submissionRepo      = $formSubmissionModel->getRepository();
        $results             = $submissionRepo->getSubmissions($options);
        $formSubmissions     = [];
        foreach ($results as $result) {
            if (!isset($formSubmissions[$result['lead_id']])) {
                $formSubmissions[$result['lead_id']] = [];
            }
            $formSubmissions[$result['lead_id']][] = $result;
        }
        unset($results);

        $translator = $this->getTranslator();
        foreach ($leadIds as $leadId) {
            $i        = 0;
            $activity = [];

            if (isset($pointChangeLog[$leadId])) {
                foreach ($pointChangeLog[$leadId] as $row) {
                    $typeString = "mautic.{$row['type']}.{$row['type']}";
                    $typeString = ($translator->hasId($typeString)) ? $translator->trans($typeString) : ucfirst($row['type']);
                    if ((int) $row['delta'] > 0) {
                        $subject = 'added';
                    } else {
                        $subject = 'subtracted';
                        $row['delta'] *= -1;
                    }
                    $pointsString = $translator->transChoice(
                        "mautic.salesforce.activity.points_{$subject}",
                        $row['delta'],
                        ['%points%' => $row['delta']]
                    );
                    $activity[$i]['eventType']   = 'point';
                    $activity[$i]['name']        = $translator->trans('mautic.salesforce.activity.point')." ($pointsString)";
                    $activity[$i]['description'] = "$typeString: {$row['eventName']} / {$row['actionName']}";
                    $activity[$i]['dateAdded']   = $row['dateAdded'];
                    $activity[$i]['id']          = 'pointChange'.$row['id'];
                    ++$i;
                }
            }

            if (isset($emailStats[$leadId])) {
                foreach ($emailStats[$leadId] as $row) {
                    switch (true) {
                        case !empty($row['storedSubject']):
                            $name = $row['storedSubject'];
                            break;
                        case !empty($row['subject']):
                            $name = $row['subject'];
                            break;
                        case !empty($row['email_name']):
                            $name = $row['email_name'];
                            break;
                        default:
                            $name = $translator->trans('mautic.email.timeline.event.custom_email');
                    }

                    $activity[$i]['eventType']   = 'email';
                    $activity[$i]['name']        = $translator->trans('mautic.salesforce.activity.email').": $name";
                    $activity[$i]['description'] = $translator->trans('mautic.email.sent').": $name";
                    $activity[$i]['dateAdded']   = $row['dateSent'];
                    $activity[$i]['id']          = 'emailStat'.$row['id'];
                    ++$i;
                }
            }

            if (isset($formSubmissions[$leadId])) {
                foreach ($formSubmissions[$leadId] as $row) {
                    $activity[$i]['eventType']   = 'form';
                    $activity[$i]['name']        = $this->getTranslator()->trans('mautic.salesforce.activity.form').': '.$row['name'];
                    $activity[$i]['description'] = $translator->trans('mautic.form.event.submitted').': '.$row['name'];
                    $activity[$i]['dateAdded']   = $row['dateSubmitted'];
                    $activity[$i]['id']          = 'formSubmission'.$row['id'];
                    ++$i;
                }
            }

            $leadActivity[$leadId] = [
                'records' => $activity,
            ];

            unset($activity);
        }

        unset($pointChangeLog, $emailStats, $formSubmissions);

        return $leadActivity;
    }

    /**
     * Return key recognized by integration.
     *
     * @param $key
     * @param $field
     *
     * @return mixed
     */
    public function convertLeadFieldKey($key, $field)
    {
        $search = [];
        foreach ($this->objects as $object) {
            $search[] = '__'.$object;
        }

        return str_replace($search, '', $key);
    }

    /**
     * @param array $params
     *
     * @return mixed
     */
    public function pushLeads($params = [])
    {
        $limit                 = $params['limit'];
        $config                = $this->mergeConfigToFeatureSettings();
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $mauticData            = [];
        $fieldsToUpdateInSf    = isset($config['update_mautic']) ? array_keys($config['update_mautic'], 1) : [];
        $fields                = implode(', l.', $config['leadFields']);
        $fields                = 'l.'.$fields;
        $result                = 0;
        $checkEmailsInSF       = [];

        $leadSfFields    = $this->cleanSalesForceData($config, array_keys($config['leadFields']), 'Lead');
        $leadSfFields    = array_diff_key($leadSfFields, array_flip($fieldsToUpdateInSf));
        $contactSfFields = $this->cleanSalesForceData($config, array_keys($config['leadFields']), 'Contact');

        $contactSfFields = array_diff_key($contactSfFields, array_flip($fieldsToUpdateInSf));
        $availableFields = $this->getAvailableLeadFields(['feature_settings' => ['objects' => ['Lead', 'Contact']]]);

        //update lead/contact records
        $leadsToUpdate = $integrationEntityRepo->findLeadsToUpdate('Salesforce', 'lead', $fields, $limit);
        foreach ($leadsToUpdate as $lead) {
            if (isset($lead['email']) && !empty($lead['email'])) {
                $checkEmailsInSF[mb_strtolower($lead['email'])] = $lead;
            }
        }

        // Only get the max limit
        if ($limit) {
            $limit -= count($leadsToUpdate);
        }

        //create lead records
        if (null === $limit || $limit) {
            $leadsToCreate = $integrationEntityRepo->findLeadsToCreate('Salesforce', $fields, $limit);
            foreach ($leadsToCreate as $lead) {
                $checkEmailsInSF[mb_strtolower($lead['email'])] = $lead;
            }
        }

        $deletedSFLeads      = [];
        $salesforceIdMapping = [];
        if ($checkEmailsInSF) {
            $findLead = 'select Id, ConvertedContactId, Email, IsDeleted from Lead where isDeleted = false and Email in (\''.implode("','", array_keys($checkEmailsInSF))
                .'\')';
            $queryUrl = $this->getQueryUrl();
            $sfLead   = $this->getApiHelper()->request('query', ['q' => $findLead], 'GET', false, null, $queryUrl);
            if ($sfLeadRecords = $sfLead['records']) {
                foreach ($sfLeadRecords as $sfLeadRecord) {
                    $key = mb_strtolower($sfLeadRecord['Email']);
                    if (isset($checkEmailsInSF[$key])) {
                        $isConverted = (isset($sfLeadRecord['ConvertedContactId'])
                            && $sfLeadRecord['ConvertedContactId'] != null);
                        $salesforceIdMapping[$checkEmailsInSF[$key]['internal_entity_id']] = ($isConverted) ? $sfLeadRecord['ConvertedContactId']
                            : $sfLeadRecord['Id'];

                        if (empty($sfLeadRecord['IsDeleted'])) {
                            $this->buildCompositeBody(
                                $mauticData,
                                $availableFields,
                                $isConverted ? $contactSfFields : $leadSfFields,
                                $isConverted ? 'Contact' : 'Lead',
                                $checkEmailsInSF[$key],
                                $isConverted ? $sfLeadRecord['ConvertedContactId'] : $sfLeadRecord['Id']
                            );
                        } else {
                            // @todo - Salesforce doesn't seem to be returning deleted contacts by default
                            $deletedSFLeads[] = $sfLeadRecord['Id'];
                            if (!empty($sfLeadRecord['ConvertedContactId'])) {
                                $deletedSFLeads[] = $sfLeadRecord['ConvertedContactId'];
                            }
                        }

                        unset($checkEmailsInSF[$key]);
                    } // Otherwise a duplicate in Salesforce and has already been processed
                }
            }
        }

        // If there are any deleted, mark it as so to prevent them from being queried over and over or recreated
        if ($deletedSFLeads) {
            $integrationEntityRepo->markAsDeleted($deletedSFLeads, $this->getName(), 'lead');
        }

        // Create any left over
        if ($checkEmailsInSF) {
            foreach ($checkEmailsInSF as $lead) {
                $this->buildCompositeBody(
                    $mauticData,
                    $availableFields,
                    $leadSfFields,
                    'Lead',
                    $lead
                );
            }
        }

        $request['allOrNone']        = 'false';
        $request['compositeRequest'] = array_values($mauticData);

        /** @var SalesforceApi $apiHelper */
        $apiHelper = $this->getApiHelper();
        if (!empty($request)) {
            $result = $apiHelper->syncMauticToSalesforce($request);
        }

        return $this->processCompositeResponse($result['compositeResponse'], $salesforceIdMapping);
    }

    /**
     * @param $lead
     *
     * @return array
     */
    public function getSalesforceLeadId($lead)
    {
        /** @var IntegrationEntityRepository $integrationEntityRepo */
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        $result                = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', null, 'Lead', $lead->getId());
        if (empty($result)) {
            //try searching for lead as this has been changed before in updated done to the plugin
            $result = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', null, 'lead', $lead->getId());
        }

        return $result;
    }

    /**
     * @param array $fields
     *
     * @return array
     *
     * @deprecated 2.6.0 to be removed in 3.0
     */
    public function amendToSfFields($fields)
    {
    }

    /**
     * @param      $mauticData
     * @param      $availableFields
     * @param      $fieldsToUpdateInSfUpdate
     * @param      $object
     * @param      $lead
     * @param null $objectId
     */
    protected function buildCompositeBody(&$mauticData, $availableFields, $fieldsToUpdateInSfUpdate, $object, $lead, $objectId = null)
    {
        $body = [];
        if (isset($lead['email']) && !empty($lead['email'])) {
            //use a composite patch here that can update and create (one query) every 200 records
            foreach ($fieldsToUpdateInSfUpdate as $sfField => $mauticField) {
                $required = !empty($availableFields[$object][$sfField.'__'.$object]['required']);
                if (isset($lead[$mauticField])) {
                    $body[$sfField] = $lead[$mauticField];
                }

                if ($required && empty($body[$sfField])) {
                    $body[$sfField] = $this->factory->getTranslator()->trans('mautic.integration.form.lead.unknown');
                }
            }

            if (!empty($body)) {
                $url = '/services/data/v38.0/sobjects/'.$object;
                if ($objectId) {
                    $url .= '/'.$objectId;
                }
                $id              = $lead['internal_entity_id'].'-'.$object.(!empty($lead['id']) ? '-'.$lead['id'] : '');
                $mauticData[$id] = [
                    'method'      => ($objectId) ? 'PATCH' : 'POST',
                    'url'         => $url,
                    'referenceId' => $id,
                    'body'        => $body,
                ];
            }
        }
    }

    /**
     * @param       $response
     * @param array $salesforceIdMapping
     *
     * @return array
     */
    protected function processCompositeResponse($response, array $salesforceIdMapping = [])
    {
        $created = 0;
        $updated = 0;

        if (is_array($response)) {
            $persistEntities = [];
            foreach ($response as $item) {
                $contactId = $integrationEntityId = null;
                if (!empty($item['referenceId'])) {
                    $reference = explode('-', $item['referenceId']);
                    if (3 === count($reference)) {
                        list($contactId, $object, $integrationEntityId) = $reference;
                    }
                    if (4 === count($reference)) {
                        list($contactId, $object, $integrationEntityId, $campaignId) = $reference;
                    } else {
                        list($contactId, $object) = $reference;
                    }
                }

                if (isset($item['body'][0]['errorCode'])) {
                    $this->logIntegrationError(new \Exception($item['body'][0]['message']));

                    if ($integrationEntityId && $object !== 'CampaignMember') {
                        $integrationEntity = $this->em->getReference('MauticPluginBundle:IntegrationEntity', $integrationEntityId);
                        $integrationEntity->setLastSyncDate(new \DateTime());

                        $persistEntities[] = $integrationEntity;
                    } elseif ($campaignId) {
                        $integrationEntity = $this->em->getReference('MauticPluginBundle:IntegrationEntity', $campaignId);
                        $integrationEntity->setLastSyncDate(new \DateTime());

                        $persistEntities[] = $integrationEntity;
                    } elseif ($contactId) {
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity->setDateAdded(new \DateTime());
                        $integrationEntity->setLastSyncDate(new \DateTime());
                        $integrationEntity->setIntegration($this->getName());
                        $integrationEntity->setIntegrationEntity($object);
                        $integrationEntity->setInternalEntity('lead-error');
                        $integrationEntity->setInternal(['error' => $item['body'][0]['message']]);
                        $integrationEntity->setInternalEntityId($contactId);
                        $persistEntities[] = $integrationEntity;
                    }
                } elseif (!empty($item['body']['success'])) {
                    if (201 === $item['httpStatusCode']) {
                        // New object created
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity->setDateAdded(new \DateTime());
                        $integrationEntity->setLastSyncDate(new \DateTime());
                        $integrationEntity->setIntegration($this->getName());
                        $integrationEntity->setIntegrationEntity($object);
                        if ($object === 'CampaignMember') {
                            $integrationEntity->setIntegrationEntityId($integrationEntityId);
                            $integrationEntity->setInternal(['Id' => $item['body']['id']]);
                        } else {
                            $integrationEntity->setIntegrationEntityId($item['body']['id']);
                        }
                        $integrationEntity->setInternalEntity('lead');
                        $integrationEntity->setInternalEntityId($contactId);
                        $persistEntities[] = $integrationEntity;
                    }
                    ++$created;
                } elseif (204 === $item['httpStatusCode']) {
                    // Record was updated
                    if ($integrationEntityId) {
                        $integrationEntity = $this->em->getReference('MauticPluginBundle:IntegrationEntity', $integrationEntityId);
                        $integrationEntity->setLastSyncDate(new \DateTime());
                    } else {
                        // Found in Salesforce so create a new record for it
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity->setDateAdded(new \DateTime());
                        $integrationEntity->setLastSyncDate(new \DateTime());
                        $integrationEntity->setIntegration($this->getName());
                        $integrationEntity->setIntegrationEntity($object);
                        $integrationEntity->setIntegrationEntityId($salesforceIdMapping[$contactId]);
                        $integrationEntity->setInternalEntity('lead');
                        $integrationEntity->setInternalEntityId($contactId);
                    }

                    $persistEntities[] = $integrationEntity;
                    ++$updated;
                } else {
                    $error = 'http status code '.$item['httpStatusCode'];
                    switch (true) {
                        case !empty($item['body'][0]['message']['message']):
                            $error = $item['body'][0]['message']['message'];
                            break;
                        case !empty($item['body']['message']):
                            $error = $item['body']['message'];
                            break;
                    }

                    $this->logIntegrationError(new \Exception($error.' ('.$item['referenceId'].')'));
                }
            }

            if ($persistEntities) {
                $this->em->getRepository('MauticPluginBundle:IntegrationEntity')->saveEntities($persistEntities);
                unset($persistEntities);
                $this->em->clear(IntegrationEntity::class);
            }
        }

        return [$updated, $created];
    }

    public function getCampaigns()
    {
        $silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;
        $campaigns         = [];
        try {
            $campaigns = $this->getApiHelper()->getCampaigns();
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
            if (!$silenceExceptions) {
                throw $e;
            }
        }

        return $campaigns;
    }

    public function getCampaignMemberStatus($campaignId)
    {
        $silenceExceptions    = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;
        $campaignMemberStatus = [];
        try {
            $campaignMemberStatus = $this->getApiHelper()->getCampaignMemberStatus($campaignId);
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
            if (!$silenceExceptions) {
                throw $e;
            }
        }

        return $campaignMemberStatus;
    }

    public function getCampaignMembers($campaignId, $settings)
    {
        $silenceExceptions = (isset($settings['silence_exceptions'])) ? $settings['silence_exceptions'] : true;
        $existingLeads     = $existingContacts     = [];

        try {
            $campaignsMembersResults = $this->getApiHelper()->getCampaignMembers($campaignId);
        } catch (\Exception $e) {
            $this->logIntegrationError($e);
            if (!$silenceExceptions) {
                throw $e;
            }
        }

        //prepare contacts to import to mautic contacts to delete from mautic
        if (isset($campaignsMembersResults['records']) && !empty($campaignsMembersResults['records'])) {
            foreach ($campaignsMembersResults['records'] as $campaignMember) {
                $contactType = !empty($campaignMember['LeadId']) ? 'Lead' : 'Contact';
                $contactId   = !empty($campaignMember['LeadId']) ? $campaignMember['LeadId'] : $campaignMember['ContactId'];
                $isDeleted   = ($campaignMember['IsDeleted']) ? true : false;
                if ($contactType == 'Lead') {
                    $leadList[$contactId] = [
                       'type'       => $contactType,
                       'id'         => $contactId,
                       'campaignId' => $campaignMember['CampaignId'],
                       'isDeleted'  => $isDeleted,
                   ];
                }
                if ($contactType == 'Contact') {
                    $contactList[$contactId] = [
                       'type'       => $contactType,
                       'id'         => $contactId,
                       'campaignId' => $campaignMember['CampaignId'],
                       'isDeleted'  => $isDeleted,
                   ];
                }
            }

            /** @var IntegrationEntityRepository $integrationEntityRepo */
            $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
            //update lead/contact records
            $listOfLeads = implode('", "', array_keys($leadList));
            $listOfLeads = '"'.$listOfLeads.'"';
            $leads       = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', 'Lead', 'lead', null, null, null, false, 0, 0, $listOfLeads);

            $listOfContacts = implode('", "', $contactList);
            $listOfContacts = '"'.$listOfContacts.'"';
            $contacts       = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', 'Contact', 'lead', null, null, null, false, 0, 0, $listOfContacts);

            if (!empty($leads)) {
                $existingLeads = array_map(function ($lead) {
                    if (($lead['integration_entity'] == 'Lead')) {
                        return $lead['integration_entity_id'];
                    }
                }, $leads);
            }
            if (!empty($contacts)) {
                $existingContacts = array_map(function ($lead) {
                    return ($lead['integration_entity'] == 'Contact') ? $lead['integration_entity_id'] : [];
                }, $contacts);
            }
            //record campaigns in integration entity for segment to process
            $allCampaignMembers = array_merge(array_values($existingLeads), array_values($existingContacts));
            //Leads
            $leadsToFetch = array_diff_key($leadList, $existingLeads);
            if (!empty($leadsToFetch)) {
                $listOfLeadsToFetch = implode("','", array_keys($leadsToFetch));
                $listOfLeadsToFetch = "'".$listOfLeadsToFetch."'";

                $mixedFields = $this->getIntegrationSettings()->getFeatureSettings();
                $fields      = $this->getMixedLeadFields($mixedFields, 'Lead');
                $fields[]    = 'Id';
                $fields      = implode(', ', array_unique($fields));
                $leadQuery   = 'SELECT '.$fields.' from Lead where Id in ('.$listOfLeadsToFetch.')';
                $executed    = 0;
                $this->getLeads([], $leadQuery, $executed, [], 'Contact');

                $allCampaignMembers = array_merge($allCampaignMembers, array_keys($leadsToFetch));
            }
            //Contacts
            $contactsToFetch = array_diff_key($contactList, $existingContacts);
            if (!empty($contactsToFetch)) {
                $listOfContactsToFetch = implode(',', $contactsToFetch);
                $listOfContactsToFetch = "'".$listOfContactsToFetch."'";

                $fields       = $this->getMixedLeadFields($mixedFields, 'Contact');
                $fields[]     = 'Id';
                $fields       = implode(', ', array_unique($fields));
                $contactQuery = 'SELECT '.$fields.' from Contact where Id in ('.$listOfContactsToFetch.')';
                $this->getLeads([], $contactQuery);
                $allCampaignMembers = array_merge($allCampaignMembers, array_keys($contactsToFetch));
            }
            if (!empty($allCampaignMembers)) {
                $internalLeadIds = implode('", "', $allCampaignMembers);
                $internalLeadIds = '"'.$internalLeadIds.'"';
                $leads           = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', null, 'lead', null, null, null, false, 0, 0, $internalLeadIds);
                //first find existing campaign members.
                foreach ($leads as $campaignMember) {
                    $existingCampaignMember = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', 'CampaignMember', 'lead', $campaignMember['internal_entity_id']);
                    if (empty($existingCampaignMember)) {
                        $integrationEntity = new IntegrationEntity();
                        $integrationEntity->setDateAdded(new \DateTime());
                        $integrationEntity->setLastSyncDate(new \DateTime());
                        $integrationEntity->setIntegration($this->getName());
                        $integrationEntity->setIntegrationEntity('CampaignMember');
                        $integrationEntity->setIntegrationEntityId($campaignId);
                        $integrationEntity->setInternalEntity('lead');
                        $integrationEntity->setInternalEntityId($campaignMember['internal_entity_id']);
                        $persistEntities[] = $integrationEntity;
                    }
                }

                if ($persistEntities) {
                    $this->em->getRepository('MauticPluginBundle:IntegrationEntity')->saveEntities($persistEntities);
                    unset($persistEntities);
                    $this->em->clear(IntegrationEntity::class);
                }
            }
        }
    }

    public function getMixedLeadFields($fields, $object)
    {
        $mixedFields = array_filter($fields['leadFields']);
        $fields      = [];
        foreach ($mixedFields as $sfField => $mField) {
            if (strpos($sfField, '__'.$object) !== false) {
                $fields[] = str_replace('__'.$object, '', $sfField);
            }
            if (strpos($sfField, '-'.$object) !== false) {
                $fields[] = str_replace('-'.$object, '', $sfField);
            }
        }

        return $fields;
    }

    public function pushLeadToCampaign(Lead $lead, $integrationCampaignId, $status)
    {
        $mauticData = $salesforceIdMapping = [];
        /** @var IntegrationEntityRepository $integrationEntityRepo */
        $integrationEntityRepo = $this->em->getRepository('MauticPluginBundle:IntegrationEntity');
        //find campaignMember
        $existingCampaignMember = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', 'CampaignMember', 'lead', $lead->getId(), null, null, null, false, 0, 0, "'".$integrationCampaignId."'");

        $body = [
            'CampaignId' => $integrationCampaignId,
            'Status'     => $status,
        ];

        $object = 'CampaignMember';
        $url    = '/services/data/v38.0/sobjects/'.$object;
        if ($existingCampaignMember) {
            foreach ($existingCampaignMember as $member) {
                $integrationEntity      = $integrationEntityRepo->getEntity($member['id']);
                $referenceId            = $integrationEntity->getId();
                $campaignMemberInternal = $integrationEntity->getInternal();
                $objectId               = $campaignMemberInternal['Id'];
                unset($body['CampaignId']);
            }
        }
        $existingLeadIntegrationIds = $integrationEntityRepo->getIntegrationsEntityId('Salesforce', null, 'lead', $lead->getId());

        if ($existingLeadIntegrationIds and empty($existingCampaignMember)) {
            foreach ($existingLeadIntegrationIds as $existingLead) {
                if ($existingLead['integration_entity'] == 'Contact') {
                    $body['ContactId'] = $existingLead['integration_entity_id'];
                    break;
                } elseif ($existingLead['integration_entity'] == 'Lead') {
                    $body['LeadId'] = $existingLead['integration_entity_id'];
                    break;
                }
            }
        } elseif (empty($existingCampaignMember)) {
            $integration_entity_id = $this->pushLead($lead);
            $body['LeadId']        = $integration_entity_id;
        }

        if ($objectId) {
            $url .= '/'.$objectId;
            $salesforceIdMapping = [$integrationCampaignId];
        }
        $id              = (!empty($lead->getId()) ? $lead->getId() : '').'-CampaignMember'.(!empty($referenceId) ? '-'.$referenceId : '').'-'.$integrationCampaignId;
        $mauticData[$id] = [
            'method'      => ($objectId) ? 'PATCH' : 'POST',
            'url'         => $url,
            'referenceId' => $id,
            'body'        => $body,
        ];

        $request['allOrNone']        = 'false';
        $request['compositeRequest'] = array_values($mauticData);

        /** @var SalesforceApi $apiHelper */
        $apiHelper = $this->getApiHelper();

        if (!empty($request)) {
            $result = $apiHelper->syncMauticToSalesforce($request);
        }

        return $this->processCompositeResponse($result['compositeResponse'], $salesforceIdMapping);
    }
}
