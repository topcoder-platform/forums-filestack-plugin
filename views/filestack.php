<?php defined('APPLICATION') or die;

$maxSizeStr = c('Garden.Upload.MaxFileSize', ini_get('upload_max_filesize'));
$units = substr($maxSizeStr, -1);
$maxSizeValue = intval(substr($maxSizeStr, 0,-1));
$maxSize = 0;
if($units == 'K') {
    $maxSize = $maxSizeValue*1024;
} else if($units == 'M' ) {
    $maxSize = $maxSizeValue*1024*1024;
} else if ($units == 'G') {
    $maxSize = $maxSizeValue*1024*1024*1024;
}
$allowedExtensions = c('Garden.Upload.AllowedFileExtensions', []);
$acceptedMimeTypes = sprintf("['.%s']", implode("','.", $allowedExtensions ) );
?>

<script src="//static.filestackapi.com/filestack-js/3.x.x/filestack.min.js" crossorigin="anonymous"></script>
<script>
    /* nothing here */
    (function($){
        $(document).on('contentLoad', function(e) {
            // Set up the picker
            const client = filestack.init("<?php echo c('Plugins.Filestack.ApiKey')?>");
            const options = {
                onUploadDone: (result) => {
                    if(result && result.filesUploaded.length > 0) {
                        var urlBox = $('#urlBox');
                        for(var i = 0; i < result.filesUploaded.length; i++) {
                            const fileData = result.filesUploaded[i];
                            const url = '<a href="' + fileData.url + '">' + fileData.url + '</a> <br/>';
                            urlBox.append(url);
                        }
                    }
                },
                maxSize: <?php echo $maxSize?>,
                accept: <?php echo $acceptedMimeTypes?>,
                uploadInBackground: false
            };
            const picker = client.picker(options);
            const btn = $('#picker')[0];
            // Add our event listeners
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                picker.open();
                var urlBox = $('#urlBox');
                urlBox.empty()
            });
        })
    }(jQuery));
</script>
<h2>JavaScript File Picker</h2>
<br/>
<div class="p">Getting Started with Web File Picker.</div>

<div class="box">
    <form id="pick-form">
        <div class="field">
            <div class="control">
                <button class="button" type="button" id="picker">Pick file</button>
                <input type="hidden" id="fileupload">
            </div>
            <div class="control" id="urlBox"></div>
        </div>
    </form>
</div>