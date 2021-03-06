<?php

use SimpleSAML\Module\perun\AdapterRpc;
use SimpleSAML\Configuration;
use SimpleSAML\XHTML\Template;
use SimpleSAML\Error\Exception;

const CONFIG_FILE_NAME = 'module_perun.php';
const PROXY_IDENTIFIER = 'listOfSps.proxyIdentifier';
const ATTRIBUTES_DEFINITIONS = 'listOfSps.attributesDefinitions';
const SHOW_OIDC_SERVICES = 'listOfSps.showOIDCServices';

const PERUN_PROXY_IDENTIFIER_ATTR_NAME = 'listOfSps.perunProxyIdentifierAttr';
const PERUN_LOGIN_URL_ATTR_NAME = 'listOfSps.loginURLAttr';
const PERUN_TEST_SP_ATTR_NAME = 'listOfSps.isTestSpAttr';
const PERUN_SHOW_ON_SERVICE_LIST_ATTR_NAME = 'listOfSps.showOnServiceListAttr';
const PERUN_SAML2_ENTITY_ID_ATTR_NAME = 'listOfSps.SAML2EntityIdAttr';
const PERUN_OIDC_CLIENT_ID_ATTR_NAME = 'listOfSps.OIDCClientIdAttr';

$config = Configuration::getInstance();
$conf = Configuration::getConfig(CONFIG_FILE_NAME);

$proxyIdentifier = $conf->getString(PROXY_IDENTIFIER);
if (is_null($proxyIdentifier) || empty($proxyIdentifier)) {
    throw new Exception(
        "perun:listOfSps: missing mandatory config option '" . PROXY_IDENTIFIER
        . "'."
    );
}

$perunProxyIdentifierAttr = $conf->getString(PERUN_PROXY_IDENTIFIER_ATTR_NAME);
if (is_null($perunProxyIdentifierAttr) || empty($perunProxyIdentifierAttr)) {
    throw new Exception(
        "perun:listOfSps: missing mandatory config option '"
        . PERUN_PROXY_IDENTIFIER_ATTR_NAME . "'."
    );
}

$attributesDefinitions = $conf->getArray(ATTRIBUTES_DEFINITIONS);
if (is_null($attributesDefinitions) || empty($attributesDefinitions)) {
    throw new Exception(
        "perun:listOfSps: missing mandatory config option '"
        . ATTRIBUTES_DEFINITIONS . "'."
    );
}

$showOIDCServices = $conf->getBoolean(SHOW_OIDC_SERVICES, false);
$perunSaml2EntityIdAttr = $conf->getString(PERUN_SAML2_ENTITY_ID_ATTR_NAME);
if (is_null($perunSaml2EntityIdAttr) || empty($perunSaml2EntityIdAttr)) {
    throw new Exception(
        "perun:listOfSps: missing mandatory config option '"
        . PERUN_SAML2_ENTITY_ID_ATTR_NAME . "'."
    );
}

$perunOidcClientIdAttr = $conf->getString(PERUN_OIDC_CLIENT_ID_ATTR_NAME);
if ($showOIDCServices
    && (is_null($perunOidcClientIdAttr)
        || empty($perunOidcClientIdAttr))
) {
    throw new Exception(
        "perun:listOfSps: missing mandatory config option '"
        . PERUN_OIDC_CLIENT_ID_ATTR_NAME . "'."
    );
}

$perunLoginURLAttr = $conf->getString(PERUN_LOGIN_URL_ATTR_NAME, null);
$perunTestSpAttr = $conf->getString(PERUN_TEST_SP_ATTR_NAME, null);
$perunShowOnServiceListAttr
    = $conf->getString(PERUN_SHOW_ON_SERVICE_LIST_ATTR_NAME, null);

$rpcAdapter = new AdapterRpc();
$attributeDefinition = [];
$attributeDefinition[$perunProxyIdentifierAttr] = $proxyIdentifier;
$facilities
    = $rpcAdapter->searchFacilitiesByAttributeValue($attributeDefinition);

$attrNames = [];

array_push($attrNames, $perunSaml2EntityIdAttr);
if (!is_null($perunOidcClientIdAttr) && !empty($perunOidcClientIdAttr)) {
    array_push($attrNames, $perunOidcClientIdAttr);
}
if (!is_null($perunLoginURLAttr) && !empty($perunLoginURLAttr)) {
    array_push($attrNames, $perunLoginURLAttr);
}
if (!is_null($perunTestSpAttr) && !empty($perunTestSpAttr)) {
    array_push($attrNames, $perunTestSpAttr);
}
if (!is_null($perunShowOnServiceListAttr)
    && !empty($perunShowOnServiceListAttr)
) {
    array_push($attrNames, $perunShowOnServiceListAttr);
}
foreach ($attributesDefinitions as $attributeDefinition) {
    array_push($attrNames, $attributeDefinition);
}

$samlServices = [];
$oidcServices = [];
$samlTestServicesCount = 0;
$oidcTestServicesCount = 0;
foreach ($facilities as $facility) {
    $attributes = $rpcAdapter->getFacilityAttributes($facility, $attrNames);

    $facilityAttributes = [];
    foreach ($attributes as $attribute) {
        $facilityAttributes[$attribute['name']] = $attribute;
    }
    if (!is_null($facilityAttributes[$perunSaml2EntityIdAttr]['value'])
        && !empty($facilityAttributes[$perunSaml2EntityIdAttr]['value'])
    ) {
        $samlServices[$facility->getId()] = [
            'facility' => $facility,
            'loginURL' => $facilityAttributes[$perunLoginURLAttr],
            'showOnServiceList' => $facilityAttributes[$perunShowOnServiceListAttr],
            'facilityAttributes' => $facilityAttributes
        ];
        if ($facilityAttributes[$perunTestSpAttr]['value']) {
            $samlTestServicesCount++;
        }
    }

    if ($showOIDCServices
        && (!is_null($facilityAttributes[$perunOidcClientIdAttr]['value'])
            && !empty($facilityAttributes[$perunOidcClientIdAttr]['value']))
    ) {
        $oidcServices[$facility->getId()] = [
            'facility' => $facility,
            'loginURL' => $facilityAttributes[$perunLoginURLAttr],
            'showOnServiceList' => $facilityAttributes[$perunShowOnServiceListAttr],
            'facilityAttributes' => $facilityAttributes
        ];
        if ($facilityAttributes[$perunTestSpAttr]['value']) {
            $oidcTestServicesCount++;
        }
    }
}

$statistics = [];
$statistics['samlServicesCount'] = sizeof($samlServices);
$statistics['samlTestServicesCount'] = $samlTestServicesCount;
$statistics['oidcServicesCount'] = sizeof($oidcServices);
$statistics['oidcTestServicesCount'] = $oidcTestServicesCount;

$attributesToShow = [];
foreach ($attrNames as $attrName) {
    if ($attrName != $perunLoginURLAttr
        && $attrName != $perunShowOnServiceListAttr
        && $attrName != $perunTestSpAttr
        && $attrName != $perunOidcClientIdAttr
        && $attrName != $perunSaml2EntityIdAttr
    ) {
        array_push($attributesToShow, $attrName);
    }
}

$allServices = array_merge($samlServices, $oidcServices);
usort($allServices, 'sortByName');

if (isset($_GET['output']) && $_GET['output'] === 'json') {
    $json = [];
    $json['services'] = [];

    $json['statistics']['samlProductionServicesCount'] = $statistics['samlServicesCount']
        - $statistics['samlTestServicesCount'];
    $json['statistics']['samlTestServicesCount'] = $statistics['samlTestServicesCount'];
    $json['statistics']['oidcProductionServicesCount'] = $statistics['oidcServicesCount']
        - $statistics['oidcTestServicesCount'];
    $json['statistics']['oidcTestServicesCount'] = $statistics['oidcTestServicesCount'];
    foreach ($allServices as $service) {
        $a = [];
        $a['name'] = $service['facility']->getName();

        if (array_key_exists($service["facility"]->getID(), $samlServices)) {
            $a['authenticationProtocol'] = "SAML";
        } else {
            $a['authenticationProtocol'] = "OIDC";
        }

        $a['description'] = $service['facility']->getDescription();

        foreach ($attributesToShow as $attr) {
            $parsedName = explode(":", $service['facilityAttributes'][$attr]['name']);
            $key = end($parsedName);
            $a[$key] = $service['facilityAttributes'][$attr]['value'];
        }
        array_push($json['services'], $a);
    }

    header('Content-type: application/json');
    echo json_encode($json);
} else {
    $t = new Template($config, 'perun:listOfSps-tpl.php');
    $t->data['statistics'] = $statistics;
    $t->data['attributesToShow'] = $attributesToShow;
    $t->data['samlServices'] = $samlServices;
    $t->data['oidcServices'] = $oidcServices;
    $t->data['allServices'] = $allServices;
    $t->show();
}

function sortByName($a, $b)
{
    return strcmp(strtolower($a['facility']->getName()), strtolower($b['facility']->getName()));
}
