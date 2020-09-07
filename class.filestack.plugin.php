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

        // "route 'yourforum.com/vanillacontroller/howtovanillapage' to
        // 'yourforum.com/fancyShortName'"
        if (!$router->matchRoute($newRoute)) {
            $router->setRoute($newRoute, $pluginPage, 'Internal');
        }
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
     * Extra styling on the discussion view.
     *
     * @param \Vanilla\Web\Asset\LegacyAssetModel $sender
     */
    public function assetModel_styleCss_handler($sender) {
       // $sender->addCssFile('topcoder.css', 'plugins/Filestack');
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

        $sender->setData('Title', sprintf(t('%s Settings'), 'Filestack'));
        $cf->renderAll();
    }

    /**
     * Endpoint to upload files.
     *
     * @param PostController $sender
     * @param array $args Expects the first argument to be the type of the upload, either 'file', 'image', or 'unknown'.
     * @throws Exception
     * @throws Gdn_UserException
     */
    public function postController_editorUpload_create($sender, $args = []) {
        $sender->permission('Garden.Uploads.Add');

        // Grab raw upload data ($_FILES), essentially. It's only needed
        // because the methods on the Upload class do not expose all variables.
        $editorFileInputName = 'editorupload';
        $fileData = Gdn::request()->getValueFrom(Gdn_Request::INPUT_FILES, $editorFileInputName, false);

        $mimeType = $fileData['type'];
        $allowedMimeTypes = $this->getAllowedMimeTypes();

        // When a MIME type fails validation, we set it to "application/octet-stream" to prevent a malicious type.
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $fileData['type'] = 'application/octet-stream';
        }

        $discussionID = ($sender->Request->post('DiscussionID')) ? $sender->Request->post('DiscussionID') : '';

        // JSON payload of media info will get sent back to the client.
        $json = [
            'error' => 1,
            'feedback' => 'There was a problem.',
            'errors' => [],
            'payload' => []
        ];

        // Upload type is either 'file', for an upload that adds an attachment to the post, or 'image' for an upload
        // that is automatically inserted into the post. If the user uploads using drag-and-drop rather than browsing
        // for the files using one of the dropdowns, we assume images-type uploads are to be inserted into the post
        // and other uploads are to be attached to the post.

        $uploadType = val(0, $args, 'unknown');
        $uploadType = strtolower($uploadType);
        if ($uploadType !== 'image' && $uploadType !== 'file') {
            $uploadType = 'unknown';
        }

        // New upload instance
        $upload = new Gdn_FilestackUpload();

        // This will validate, such as size maxes, file extensions. Upon doing
        // this, $_FILES is set as a protected property, so all the other Gdn_Upload methods work on it.
        $tmpFilePath = $upload->validateUpload($editorFileInputName);

        // Get base destination path for editor uploads
        // $this->editorBaseUploadDestinationDir = $this->getBaseUploadDestinationDir();

        // Pass path, if doesn't exist, will create, and determine if valid.
        // $canUpload = true;// Gdn_Upload::canUpload($this->editorBaseUploadDestinationDir);

        if ($tmpFilePath) {
            $fileExtension = strtolower($upload->getUploadedFileExtension());
            $fileName = $upload->getUploadedFileName();
            list($tmpwidth, $tmpheight, $imageType) = getimagesize($tmpFilePath);

            // This will return the absolute destination path, including generated
            // filename based on md5_file, and the full path. It
            // will create a filename, with extension, and check if its dir can be writable.
            $absoluteFileDestination = $tmpFilePath.'/'.$fileName;
            if ($fileExtension) {
                $absoluteFileDestination .= '.'.$fileExtension;
            }

            // Save original file to uploads, then manipulate from this location if
            // it's a photo. This will also call events in Vanilla so other plugins can tie into this.
            $validImageTypes = [
                IMAGETYPE_GIF,
                IMAGETYPE_JPEG,
                IMAGETYPE_PNG
            ];
            $validImage = !empty($imageType) && in_array($imageType, $validImageTypes);

            if (!$validImage) {
                if ($uploadType === 'unknown') {
                    $uploadType = 'file';
                }
            } else {
                if ($uploadType === 'unknown') {
                    $uploadType = 'image';
                }

                // image dimensions are higher than limit, it needs resizing
                if (c("ImageUpload.Limits.Enabled")) {
                    if ($tmpwidth > c("ImageUpload.Limits.Width") || $tmpheight > c("ImageUpload.Limits.Height")) {
                        $imageResizer = new \Vanilla\ImageResizer();
                        $imageResizer->resize(
                            $tmpFilePath,
                            null,
                            [
                                "height" => c("ImageUpload.Limits.Height"),
                                "width" => c("ImageUpload.Limits.Width"),
                                "crop" => false
                            ]
                        );
                    }
                }

                $filePathParsed = Gdn_UploadImage::saveImageAs(
                    $tmpFilePath,
                    $absoluteFileDestination,
                    '',
                    '',
                    [
                        'OriginalFilename' => $fileName,
                        'source' => 'content',
                        'SaveGif' => true
                    ]
                );

                $tmpwidth = $filePathParsed['Width'];
                $tmpheight = $filePathParsed['Height'];
           }

            $filePathParsed = $upload->saveAs($tmpFilePath);
            # Delete a temp file
            $upload->delete($tmpFilePath);

            // Determine if image, and thus requires thumbnail generation, or simply saving the file.
            // Not all files will be images.
            $thumbHeight = null;
            $thumbWidth = null;
            $imageHeight = null;
            $imageWidth = null;
            $thumbPathParsed = ['SaveName' => ''];
            $thumbUrl = '';

            // This is a redundant check, because it's in the thumbnail function,
            // but there's no point calling it blindly on every file, so just check here before calling it.
             if ($validImage) {
                $imageHeight = $tmpheight;
                $imageWidth = $tmpwidth;
            }

            // Save data to database using model with media table
            $model = new MediaModel();

            // Will be passed to model for database insertion/update. All thumb vars will be empty.
            $media = [
                'Name' => $fileName,
                'Type' => $fileData['type'],
                'Size' => $fileData['size'],
                'ImageWidth' => $imageWidth,
                'ImageHeight' => $imageHeight,
                'ThumbWidth' => $thumbWidth,
                'ThumbHeight' => $thumbHeight,
                'InsertUserID' => Gdn::session()->UserID,
                'DateInserted' => date('Y-m-d H:i:s'),
                'Path' => $filePathParsed['SaveName'],
                'ThumbPath' => $thumbPathParsed['SaveName']
            ];

            // Get MediaID and pass it to client in payload.
            $mediaID = $model->save($media);
            $media['MediaID'] = $mediaID;

            // Escape the media's name.
            $media['Name'] = htmlspecialchars($media['Name']);

            $payload = [
                'MediaID' => $mediaID,
                'Filename' => $media['Name'],
                'Filesize' => $fileData['size'],
                'FormatFilesize' => Gdn_Format::bytes($fileData['size'], 1),
                'type' => $fileData['type'],
                'Thumbnail' => '',
                'FinalImageLocation' => '',
                'Parsed' => $filePathParsed,
                'Media' => $media,
                'original_url' => Gdn_Upload::url($filePathParsed['SaveName']),
                'thumbnail_url' => $thumbUrl,
                'original_width' => $imageWidth,
                'original_height' => $imageHeight,
                'upload_type' => $uploadType
            ];

            $json = [
                'error' => 0,
                'feedback' => 'Editor received file successfully.',
                'payload' => $payload
            ];
        }

        // Return JSON payload
        echo json_encode($json);
    }

    /**
     * Get a list of valid MIME types for file uploads.
     *
     * @return array
     */
    private function getAllowedMimeTypes() {
        $result = [];

        $allowedExtensions = c('Garden.Upload.AllowedFileExtensions', []);
        if (is_array($allowedExtensions)) {
            foreach ($allowedExtensions as $extension) {
                if ($mimeTypes = $this->lookupMime($extension)) {
                    $result = array_merge($result, $mimeTypes);
                }
            }
        }

        return $result;
    }

    /**
     * Retrieve mime type from file extension.
     *
     * @param string $extension The extension to look up. (i.e., 'png')
     * @return bool|string The mime type associated with the file extension or false if it doesn't exist.
     */
    private function lookupMime($extension){
        global $mimeTypes;
        include_once 'mimetypes.php';
        return val($extension, $mimeTypes, false);
    }
}

class Gdn_FilestackUpload {
    /** @var array */
    protected $_AllowedFileExtensions;

    /** @var int */
    protected $_MaxFileSize;

    /** @var string */
    protected $_UploadedFile;

    /** @var \Vanilla\FileUtils */
    protected $fileUtils;

    /**
     * Class constructor.
     */
    public function __construct() {
        $this->clear();
        $this->ClassName = 'Gdn_FilestackUpload';
        $this->fileUtils = Gdn::getContainer()->get(\Vanilla\FileUtils::class);
    }

    public function clear() {
        $this->_MaxFileSize = Gdn_Upload::unformatFileSize(Gdn::config('Garden.Upload.MaxFileSize', ''));
        $this->_AllowedFileExtensions = Gdn::config('Garden.Upload.AllowedFileExtensions', []);
        $this->_UploadedFile = null;
    }

    /**
     *
     *
     * @return mixed
     */
    public function getUploadedFileName() {
        return val('name', $this->_UploadedFile);
    }

    /**
     *
     *
     * @return mixed
     */
    public function getUploadedFileExtension() {
        $name = $this->_UploadedFile['name'];
        $info = pathinfo($name);
        return val('extension', $info, '');
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
     *
     *
     * @param $source
     * @param $target
     * @param array $options
     * @return array|bool
     * @throws Exception
     */
    public function saveAs($source) {
        $result = false;
        $error = null;
        $apiKey = c('Plugins.Filestack.ApiKey');
        $client = new FilestackClient($apiKey);
        try {
            $filelink = $client->upload($source);
            // get metadata of file
            $fields = [];
            $metadata = $client->getMetaData($filelink->handle, $fields);
            $parsed['Name'] = $filelink->url();
            $parsed['SaveName'] = $filelink->url();
            $result = true;
        } catch (FilestackException $e) {
            $error = $e->getCode(). ': '.$e->getMessage();
            $result = false;
        }

        if (!$result) {
            throw new Exception(sprintf(t('Failed to save uploaded file to target destination (%s).'), $error));
        }

        return $parsed;
    }

    /**
     * Validates the uploaded file.
     * Returns the temporary name of the uploaded file.
     * @param $inputName
     * @param bool $throwException
     * @return false|mixed|string
     * @throws Gdn_UserException
     */
    public function validateUpload($inputName, $throwException = true) {
        $ex = false;
        if (!array_key_exists($inputName, $_FILES) || (!$this->fileUtils->isUploadedFile($_FILES[$inputName]['tmp_name'])
                && getValue('error', $_FILES[$inputName], 0) == 0)) {
            // Check the content length to see if we exceeded the max post size.
            $contentLength = Gdn::request()->getValueFrom('server', 'CONTENT_LENGTH');
            $maxPostSize = Gdn_Upload::unformatFileSize(ini_get('post_max_size'));
            if ($contentLength > $maxPostSize) {
                $ex = sprintf(t('Gdn_Upload.Error.MaxPostSize',
                    'The file is larger than the maximum post size. (%s)'), Gdn_Upload::formatFileSize($maxPostSize));
            } else {
              $ex = t('The file failed to upload.');
            }
        } else {
            switch ($_FILES[$inputName]['error']) {
                case 1:
                case 2:
                    $maxFileSize = Gdn_Upload::unformatFileSize(ini_get('upload_max_filesize'));
                    $ex = sprintf(t('Gdn_Upload.Error.PhpMaxFileSize', 'The file is larger than the server\'s maximum file size. (%s)'), self::formatFileSize($maxFileSize));
                    break;
                case 3:
                case 4:
                    $ex = t('The file failed to upload.');
                    break;
                case 6:
                    $ex = t('The temporary upload folder has not been configured.');
                    break;
                case 7:
                    $ex = t('Failed to write the file to disk.');
                    break;
                case 8:
                    $ex = t('The upload was stopped by extension.');
                    break;
            }
        }

        $foo = Gdn_Upload::formatFileSize($this->_MaxFileSize);

       // Check the maxfilesize again just in case the value was spoofed in the form.
        if (!$ex && $this->_MaxFileSize > 0 && filesize($_FILES[$inputName]['tmp_name']) > $this->_MaxFileSize) {
            $ex = sprintf(t('Gdn_Upload.Error.MaxFileSize', 'The file is larger than the maximum file size. (%s)'),
                Gdn_Upload::formatFileSize($this->_MaxFileSize));
        } elseif (!$ex) {
            // Make sure that the file extension is allowed.

            $extension = pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION);
            if (!inArrayI($extension, $this->_AllowedFileExtensions)) {
                $ex = sprintf(t('You cannot upload files with this extension (%s). Allowed extension(s) are %s.'),
                    htmlspecialchars($extension), implode(', ', $this->_AllowedFileExtensions));
            }
        }

         if ($ex) {
            if ($throwException) {
                throw new Gdn_UserException($ex);
            } else {
                $this->Exception = $ex;
                return false;
            }
        } else {
            // If all validations were successful, return the tmp name/location of the file.
            $this->_UploadedFile = $_FILES[$inputName];
            return $this->_UploadedFile['tmp_name'];
        }
    }
}
