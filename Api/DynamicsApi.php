<?php

namespace MauticPlugin\MauticCrmBundle\Api;

use Joomla\Http\Response;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Exception\ApiErrorException;

class DynamicsApi extends CrmApi
{
    /**
     * @return string
     */
    private function getUrl()
    {
        $keys = $this->integration->getKeys();

        return $keys['resource'].'/api/data/v8.2';
    }

    /**
     * @param $operation
     * @param array  $parameters
     * @param string $method
     * @param string $moduleobject
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    protected function request($operation, array $parameters = [], $method = 'GET', $moduleobject = 'contact', $settings = [])
    {
        if (0 === strpos($operation, 'EntityDefinitions')) {
            $url = sprintf('%s/%s', $this->getUrl(), $operation);
        } else {
            $moduleobject .= 's'; // pluralize object
            $url = sprintf('%s/%s/%s', $this->getUrl(), $moduleobject, $operation);
            if ('POST' === $method) {
                $url = sprintf('%s/%s', $this->getUrl(), $moduleobject);
            }
        }
        $keys     = $this->integration->getKeys();
        $settings = array_merge($settings, [
            'encode_parameters' => 'json',
            'return_raw'        => 'true', // needed to get the HTTP status code in the response
        ]);

        /** @var Response $response */
        $response = $this->integration->makeRequest($url, $parameters, $method, $settings);

        if (204 === $response->code) {
            return '';
        }

        if (property_exists($response, 'body')) {
            return json_decode($response->body, true);
        }

        return [];
    }

    /**
     * List types.
     *
     * @param string $object Zoho module name
     *
     * @return mixed
     */
    public function getLeadFields($object = 'contact')
    {
        if ('company' === $object) {
            $object = 'account'; // Dynamics object name
        }

        $operation  = sprintf('EntityDefinitions(LogicalName=\'%s\')/Attributes', $object);
        $parameters = [
            'filter'  => 'AttributeOf eq null', // ignore system fields
            '$select' => 'RequiredLevel,LogicalName,AttributeType,DisplayName,IsValidForUpdate', // select only miningful columns
        ];

        return $this->request($operation, $parameters, 'GET', $object);
    }

    /**
     * @param $data
     * @param Lead $lead
     * @param $object
     *
     * @return array
     */
    public function createLead($data, $lead, $object = 'contact')
    {
        return $this->request('', $data, 'POST', $object);
    }

    /**
     * gets Zoho leads.
     *
     * @param array  $params
     * @param string $object
     *
     * @return mixed
     */
    public function getLeads(array $params, $object)
    {
        if (!isset($params['selectColumns'])) {
            $params['selectColumns'] = 'All';
            $params['newFormat']     = 1;
        }

        $data = $this->request('getRecords', $params, 'GET', $object);
        if (isset($data['response'], $data['response']['result'])) {
            $data = $data['response']['result'];
        }

        return $data;
    }

    /**
     * gets Zoho companies.
     *
     * @param array  $params
     * @param string $id
     *
     * @return mixed
     */
    public function getCompanies(array $params, $id = null)
    {
        if (!isset($params['selectColumns'])) {
            $params['selectColumns'] = 'All';
        }

        if ($id) {
            $params['id'] = $id;

            $data = $this->request('getRecordById', $params, 'GET', 'Accounts');
        } else {
            $data = $this->request('getRecords', $params, 'GET', 'Accounts');
        }

        if (isset($data['response'], $data['response']['result'])) {
            $data = $data['response']['result'];
        }

        return $data;
    }
}