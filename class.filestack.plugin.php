<?php
/**
 * Class FilestackPlugin
 */

if (!class_exists('Filestack\FilestackClient')){
    require __DIR__ . '/vendor/autoload.php';
}

use Garden\Schema\Schema;
use Garden\Web\Data;
use Garden\Web\Exception\NotFoundException;
use Vanilla\ApiUtils;
use Filestack\FilestackClient;
use Filestack\Filelink;
use Filestack\FilestackException;

class FilestackPlugin extends Gdn_Plugin {

    const SHORT_ROUTE = 'filestack';
    const LONG_ROUTE = 'vanilla/filestack';
    const PAGE_NAME = 'Filestack';

    /**
     * Setup is run whenever plugin is enabled.
     *
     * This is the best place to create a custom route for our page. That will
     * make a pretty url for a otherwise clumsy slug.
     *
     * @return void.
     * @package HowToVanillaPage
     * @since 0.1
     */
    public function setup() {
        // get a reference to Vanillas routing class
        $router = Gdn::router();

        // this is the ugly slug we want to change
        $pluginPage = self::LONG_ROUTE.'$1';

        // that's how the nice url should look like
        $newRoute = '^'.self::SHORT_ROUTE.'(/.*)?$';

        if (!$router->matchRoute($newRoute)) {
            $router->setRoute($newRoute, $pluginPage, 'Internal');
        }
    }

    /**
     * Database update.
     *
     * @throws Exception
     */
    public function structure() {
        Gdn::structure()->table('Category')
            ->column('AllowFileUploads', 'tinyint(1)', '1')
            ->set();
    }

    /**
     * OnDisable is run whenever plugin is disabled.
     *
     * We have to delete our internal route because our custom page will not be
     * accessible any more.
     *
     * @return void.
     */
    public function onDisable() {
        Gdn::router()->deleteRoute('^'.self::SHORT_ROUTE.'(/.*)?$');
    }

    /**
     * Create a link to our page in the menu.
     *
     * @param GardenController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function base_render_before($sender) {
        if ($sender->Menu && $sender->masterView() != 'admin') {
            // If current page is our custom page, we want the menu entry
            // to be selected. This is only needed if you've changed the route,
            // otherwise it will happen automatically.
            if ($sender->SelfUrl == self::SHORT_ROUTE) {
                $anchorAttributes = ['class' => 'Selected'];
            } else {
                $anchorAttributes = '';
            }

            // We add our Link to a section (but you can pass an empty string
            // if there is no group you like to add your link to), pass a name,
            // the link target and our class to the function.
            $sender->Menu->addLink(
                '',
                t(self::PAGE_NAME),
                self::SHORT_ROUTE,
                '',
                $anchorAttributes
            );
        }
    }

    /**
     * Create a new page that uses the current theme.
     *
     * By extending the Vanilla controller, we have access to all resources
     * that we need.
     *
     * @param VanillaController $sender Instance of the calling class.
     * @param array             $args   Arguments for our function passed in the url.
     *
     * @return void.
     */
    public function vanillaController_filestack_create($sender, $args) {
        // That one is critical! The template of your theme is called
        // default.master.tpl and calling this function sets the master view of
        // this controller to the default theme template.
        $sender->masterView();

        // If you've changed the route, you should change that value, too. We
        // use it for highlighting the menu entry.
        $sender->SelfUrl = self::SHORT_ROUTE;

        // If you need custom CSS or Javascript
        // add resources

        // There is a list of which modules to add to the panel for a standard
        // Vanilla page. We will add all of them, just to be sure our new page
        // looks familiar to the users.
        foreach (c('Modules.Vanilla.Panel') as $module) {
            // We have to exclude the MeModule here, because it is already added
            // by the template and it would appear twice otherwise.
            if ($module != 'MeModule') {
                $sender->addModule($module);
            }
        }

        // We can set a title for the page like that. But this is just a short
        // form for $sender->setData('Title', 'Vanilla Page');
        $sender->title(t(self::PAGE_NAME));

        // This sets the breadcrumb to our current page.
        $sender->setData(
            'Breadcrumbs',
            [['Name' => t(self::PAGE_NAME), 'Url' => self::SHORT_ROUTE]]
        );

        $sender->render($sender->fetchViewLocation('filestack', '', 'plugins/filestack'));
    }

    /**
     * The settings page for the topcoder plugin.
     *
     * @param Gdn_Controller $sender
     */
    public function settingsController_filestack_create($sender) {
        $sender->permission('Garden.Settings.Manage');

        $cf = new ConfigurationModule($sender);
        $cf->initialize([
            'Plugins.Filestack.ApiKey' => ['Control' => 'TextBox', 'Default' => '', 'Description' => 'Filestack API Key'],
            'Garden.Upload.MaxFileSize' => ['Control' => 'TextBox', 'Default' => c('Garden.Upload.MaxFileSize', ini_get('upload_max_filesize')), 'Description' => 'Allowed Max File Size. Accepted Measurements: Megabyte - M, Gigabyte - G.'],
         ]);

        if($cf->form()->authenticatedPostBack()) {
            $cf->form()->validateRule('Plugins.Filestack.ApiKey', 'ValidateRequired', t('You must provide Filestack API Key.'));
        }

        $sender->setData('Title', sprintf(t('%s Settings'), 'Filestack'));
        $cf->renderAll();
    }

    public function gdn_upload_SaveAs_handler($sender, $args){
        if(!c('Plugins.Filestack.ApiKey')) {
            return;
        }
        $parsed = & $args['Parsed'];
        $handled =& $args['Handled'];
        $options = $args['Options'];
        $fileName = val('ClientFileName', $options);
        $tmpFilePath = $args['Path'];
        $upload = new Gdn_FilestackUpload();
        $filestackOptions = [];
        if($fileName) {
            $filestackOptions['filename'] = $fileName;
        }
        $filelink = $upload->saveAs($tmpFilePath, $filestackOptions);

        $parsed['Name'] = $filelink;
        $parsed['SaveName'] = $filelink;
        # Delete a temp file
        $upload->delete($tmpFilePath);
        $handled = true;
    }
}

class Gdn_FilestackUpload {

    /** @var \Vanilla\FileUtils */
    protected $fileUtils;

    /**
     * Class constructor.
     */
    public function __construct() {
        $this->ClassName = 'Gdn_FilestackUpload';
        $this->fileUtils = Gdn::getContainer()->get(\Vanilla\FileUtils::class);
    }

    /**
     * Delete an uploaded file.
     *
     * @param string $filePath The filePath
     * @return bool
     */
    public function delete($filePath) {
        if ($filePath === realpath($filePath) && file_exists($filePath)) {
            return safeUnlink($filePath);
        }
        return true;
    }

    /**
     * Upload a file to Filestack
     *
     * @param $source
     * @param $parsed
     * @return array|bool
     * @throws Exception
     */
    public function saveAs($source, $options = []) {
        $apiKey = c('Plugins.Filestack.ApiKey');
        $client = new FilestackClient($apiKey);
        try {
            $filelink = $client->upload($source, $options);
            // get metadata of file
            // $fields = [];
            // $metadata = $client->getMetaData($filelink->handle, $fields);
            return $filelink->url();
        } catch (FilestackException $e) {
            $error = $e->getCode(). ': '.$e->getMessage();
            throw new Exception(sprintf(t('Failed to save uploaded file to target destination (%s).'), $error));
        }
    }

}
