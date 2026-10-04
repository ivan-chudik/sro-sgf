<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class Core
{
    private static $instance = null;

    // Core infrastructure (always active)
    public $participantManager;
    public $verificationManager;
    public $stripeManager;
    public $templateHandler;
    public $wooCommerceManager;
    public $failedOrderEmail;
    public $settings;
    public $assets;
    public $ticketManager;
    public $ticketDesigner;
    public $ticketDesignerSettings;
    public $qrCodeManager;
    public $languageManager;
    public $coreFilters;
    public $eventManager;
    public $checkoutManager;
    public $fieldBuilderPage;
    public $thankYouPage;
    public $adminColumnsManager;
    public $archiveManager;
    public $selectionManager;
    public $adminPreview;
    public $stripeTestCardHelper;
    public $adminTestToggles;
    public $pricingTiers;

    /** @var ProductGroup */
    public $productGroup;

    /** @var Module\ModuleRegistry */
    public $moduleRegistry;

    private function __construct()
    {
        $this->instantiateServices();
        $this->moduleRegistry = new Module\ModuleRegistry($this->settings);
        $this->registerModules();
        $this->wireDependencies();
        $this->registerHooks();
        $this->moduleRegistry->boot();
    }

    private function instantiateServices()
    {
        $this->settings = new Settings();
        $this->assets = new Assets();
        $this->participantManager = new ParticipantManager();
        $this->verificationManager = new VerificationManager();
        $this->templateHandler = new TemplateHandler();
        $this->stripeManager = new StripeManager();
        $this->wooCommerceManager = new WooCommerceManager();
        $this->failedOrderEmail = new FailedOrderEmail();
        $this->ticketManager = new TicketManager();
        $this->ticketDesigner = new TicketDesigner();
        $this->ticketDesignerSettings = new TicketDesignerSettings();
        $this->qrCodeManager = new QRCodeManager();
        $this->languageManager = new LanguageManager();
        $this->coreFilters = new CoreFilters($this->settings);
        $this->eventManager = new EventManager();
        $this->checkoutManager = new CheckoutManager();
        $this->fieldBuilderPage = new FieldBuilderPage();
        $this->thankYouPage = new ThankYouPage();
        $this->adminColumnsManager = new AdminColumnsManager();
        $this->archiveManager = new ArchiveManager();
        $this->selectionManager = new Selection\SelectionManager();
        $this->adminPreview = new AdminPreview(
            $this->wooCommerceManager,
            $this->participantManager,
            $this->stripeManager
        );
        $this->stripeTestCardHelper = new StripeTestCardHelper();
        $this->adminTestToggles = new AdminTestToggles();
        $this->pricingTiers = new PricingTiers();
        $this->productGroup = new ProductGroup();
    }

    private function registerModules()
    {
        $this->moduleRegistry->register(new Module\LivestreamModule());
        $this->moduleRegistry->register(new Module\NotificationModule());
        $this->moduleRegistry->register(new Module\WebhookImportModule());
        $this->moduleRegistry->register(new Module\MultiDayModule());
        $this->moduleRegistry->register(new Module\AccommodationModule());
        $this->moduleRegistry->register(new Module\CompanionRegistrationModule());
        $this->moduleRegistry->register(new Module\SessionModule());
        $this->moduleRegistry->register(new Module\ParticipantTypeModule());
        $this->moduleRegistry->register(new Module\MultiParticipantModule());
        $this->moduleRegistry->register(new Module\MembershipModule());
        $this->moduleRegistry->register(new Module\RecordingModule());
        $this->moduleRegistry->register(new Module\HotelSelectionModule());
        $this->moduleRegistry->register(new Module\GroupSeatingModule());
    }

    /**
     * Backward compat: access modules via old property names.
     */
    public function __get($name)
    {
        $map = [
            'livestreamManager' => 'livestream',
            'notificationManager' => 'notifications',
            'webhookImport' => 'webhook_import',
            'participantFeaturesManager' => 'extended_participant_features',
            'multiDayModule' => 'extended_participant_features',
        ];
        if (isset($map[$name])) {
            return $this->moduleRegistry->get($map[$name]);
        }
        return null;
    }

    private function wireDependencies()
    {
        $this->wooCommerceManager->setParticipantManager($this->participantManager);
        $this->wooCommerceManager->setSettings($this->settings);
        $this->wooCommerceManager->setStripeManager($this->stripeManager);
        $this->participantManager->setVerificationManager($this->verificationManager);
        $this->verificationManager->setParticipantManager($this->participantManager);
        $this->templateHandler->setVerificationManager($this->verificationManager);
        // LivestreamModule no longer needs manual dependency injection
        // (accesses Core::getInstance() if needed)
        $this->ticketManager->setVerificationManager($this->verificationManager);
        $this->ticketManager->setParticipantManager($this->participantManager);
        $this->ticketManager->setTicketDesignerSettings($this->ticketDesignerSettings);
        $this->verificationManager->setTicketManager($this->ticketManager);
        $this->verificationManager->setQRCodeManager($this->qrCodeManager);
        $this->ticketDesigner->setVerificationManager($this->verificationManager);
        $this->ticketDesigner->setParticipantManager($this->participantManager);
        $this->ticketDesigner->setTicketManager($this->ticketManager);
        $this->qrCodeManager->setVerificationManager($this->verificationManager);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function registerHooks()
    {
        add_action('plugins_loaded', [$this, 'loadTextdomain']);

        // Expose verification manager via filter for import and other uses
        add_filter('alttag_registrations_verification_manager', function () {
            return $this->verificationManager;
        });

        // Core infrastructure services (modules boot separately via ModuleRegistry)
        $this->registerServiceHooks([
            $this->assets,
            $this->settings,
            $this->verificationManager,
            $this->templateHandler,
            $this->stripeManager,
            $this->wooCommerceManager,
            $this->failedOrderEmail,
            $this->languageManager,
            $this->ticketManager,
            $this->ticketDesigner,
            $this->ticketDesignerSettings,
            $this->qrCodeManager,
            $this->coreFilters,
            $this->eventManager,
            $this->checkoutManager,
            $this->fieldBuilderPage,
            $this->thankYouPage,
            $this->adminColumnsManager,
            $this->archiveManager,
            $this->selectionManager,
            $this->adminPreview,
            $this->stripeTestCardHelper,
            $this->adminTestToggles,
            $this->pricingTiers,
            $this->dayPricingWindows,
            $this->productGroup,
        ]);
    }

    private function registerServiceHooks(array $services)
    {
        foreach ($services as $service) {
            if (!$service) {
                continue;
            }

            if (method_exists($service, 'registerHooks')) {
                $service->registerHooks();
            }

            if (method_exists($service, 'init')) {
                $service->init();
            }
        }
    }

    public function loadTextdomain()
    {
        load_muplugin_textdomain('alttag-registrations', dirname(plugin_basename(__FILE__)) . '/languages');
    }
}
