<?php
/**
* Impact: Partnership Cloud for Magento
*
* @package     Impact_Itegration
* @copyright   Copyright (c) 2021 Impact. (https://impact.com)
*/

namespace Impact\Integration\Observer;

use Impact\Integration\Model\ConfigData;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Impact\Integration\Service\ImpactApiService; 
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Impact\Integration\Helper\Data;
use Magento\Config\Model\ResourceModel\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;

/**
 * Class ConfigChange
 *
 * @package Impact\Integration\Observer
 */
class ConfigChange implements ObserverInterface
{
    /**
     * API request endpoint integration
     */
    const API_ENDPOINT_INTEGRATION = 'https://saasler-magento-impact.herokuapp.com/integration_setting';

    /**
     * Integration service
     *
     * @var _integrationService \Magento\Integration\Api\IntegrationServiceInterface
     */
    private $_integrationService;

    /**
     * Model Config
     *
     * @var config Magento\Config\Model\Config
     */
    private $config;

    /**
     * @var _resourceConfig \Magento\Config\Model\ResourceModel\Config
     */
    protected $_resourceConfig;

    /**
     * Oauth Service Interface
     *
     * @var oauthService Magento\Integration\Api\OauthServiceInterface
     */
    private $oauthService;

    /**
     * @var request
     */
    private $request;

    /**
     * @var setup
     */
    private $setup;

    /**
     * @var helper
     */
    private $helper;

    private ConfigData $configData;

    /**
     * ConfigChange constructor.
     * @param RequestInterface $request
     * @param IntegrationServiceInterface $integrationService 
     * @param Config $resourceConfig
     * @param  OauthServiceInterface $oauthService 
     * @param ModuleDataSetupInterface $setup
     * @param TypeListInterface $cacheTypeList
     * @param Pool $cacheFrontendPool
     * @param Data $helper
     * @param ConfigData $configData
     */
    public function __construct(
        RequestInterface $request, 
        IntegrationServiceInterface $integrationService, 
        Config $resourceConfig, 
        OauthServiceInterface $oauthService, 
        ModuleDataSetupInterface $setup,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        Data $helper,
        ConfigData $configData
    ) {
        $this->_resourceConfig = $resourceConfig;
        $this->oauthService = $oauthService;
        $this->_integrationService = $integrationService;
        $this->setup = $setup;
        $this->request = $request;
        $this->cacheTypeList = $cacheTypeList;
        $this->cacheFrontendPool = $cacheFrontendPool;
        $this->helper = $helper;

        $this->configData = $configData;
    }
    /**
     * Execute Function
     * 
     * @param Observer $observer
     */
    public function execute(EventObserver $observer)
    {
        // Validate if module is enable
        if ($this->helper->isEnabled()) { 
            /*
             IRC Click function
             <script type="text/javascript">
                (function() {
                    function setCookie(cookieName, cookieValue, daysUntilExpiration) {
                        const date = new Date();
                        date.setTime(date.getTime() + (daysUntilExpiration * 24 * 60 * 60 * 1000));
                        const expires = "expires="+date.toUTCString();
                        document.cookie = cookieName + "=" + cookieValue + ";" + "SameSite=None;" + expires + ";path=/;secure";
                    }
                    ire("generateClickId",function(clickId){
                        setCookie("irclickid",clickId,30)
                    }); 
                })();
                </script>
             */

            // Production Version
            $ircClickFunction = ' <script type="text/javascript"> !function(){ire("generateClickId",function(e){!function(e,i,t){const n=new Date;n.setTime(n.getTime()+24*t*60*60*1e3);const c="expires="+n.toUTCString();document.cookie=e+"="+i+";SameSite=None;"+c+";path=/;secure"}("irclickid",e,30)})}(); </script> ';
            // Developer Version
            //$ircClickFunction = ' <script type="text/javascript"> !function(){ire("generateClickId",function(e){!function(e,i,n){const t=new Date;t.setTime(t.getTime()+24*n*60*60*1e3),t.toUTCString(),document.cookie=e+"="+i}("irclickid",e,30)})}();</script>';    
            
            // Get credentials from Impact Setting form
            $params = $this->request->getParam('groups');
            $account_sid = $params['existing_customer']['fields']['account_sid']['value'] ? $params['existing_customer']['fields']['account_sid']['value'] : '';
            $auth_token = $params['existing_customer']['fields']['auth_token']['value'] ? $params['existing_customer']['fields']['auth_token']['value'] : '';
            $program_id = $params['existing_customer']['fields']['program_id']['value'] ? $params['existing_customer']['fields']['program_id']['value'] : '';
            $event_type_id = $params['existing_customer']['fields']['event_type_id']['value'] ? $params['existing_customer']['fields']['event_type_id']['value'] : '';
            $universal_tracking_tag = $params['existing_customer']['fields']['universal_tracking_tag']['value'] ? $params['existing_customer']['fields']['universal_tracking_tag']['value'] : '';
            $universal_tracking_tag = str_replace(", {customerid: '' /*INSERT CUSTOMER ID*/, customeremail: '' /*INSERT SHA1 HASHED CUSTOMER EMAIL*/}", "", $universal_tracking_tag);
            $credentials = [
                'username' => $account_sid,
                'password' => $auth_token,
                'campaign_id' => $program_id,
                'action_tracker_id' => $event_type_id
            ];
            $parameters = ['integration_setting' => $credentials];
            $body = json_encode($parameters);
            // Get accesstoken from integration
            $accessToken = $this->getAccessToken();

            // Send data with ImpactApiService class
            $impactApiService = new ImpactApiService($accessToken, static::API_ENDPOINT_INTEGRATION , 'PUT', $body);
            $response = $impactApiService->execute();

            // Get response with conversion and refund url
            $responseBody = $response->getBody();
            $responseContent = $responseBody->getContents();
            $urls = json_decode($responseContent, true);

            $urls['utt_default'] = '';
            // Validate if user input UTT
            if (isset($universal_tracking_tag) && !empty($universal_tracking_tag)) {
                $urls['utt_default'] = $universal_tracking_tag . $ircClickFunction;
            } 

            // Save conversion_url, refund_url and universal tracking tag with irc click Id Function
            $this->configData->refresh($urls);
        }

        // Clean cache
        $this->flushCache();

        return $this;
    }

    /**
     * Get access token from Impact Integration
     * 
     * @return String
     */
    private function getAccessToken()
    {
        $accessToken = '';
        // Get the Impact integration data
        $integration = $this->_integrationService->findByName('ImpactIntegration');
        if (!$integration->getId()) {
            throw new NoSuchEntityException(__('Cannot find Impact integration.'));
        }
        $consumerId = $integration->getConsumerId();
        $token = $this->oauthService->getAccessToken($consumerId);
        $accessToken = $token->getToken();
        return $accessToken;
    }

    /**
     * Flush cache
     *  
     *  @return void 
     */
    private function flushCache():void
    {
        $types = array('config','layout','block_html','collections','reflection','db_ddl','eav','config_integration','config_integration_api','full_page','translate','config_webservice');
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }
        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }
}
?>