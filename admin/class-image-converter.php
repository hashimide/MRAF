<?php
if (!defined('ABSPATH'))
    exit;

class MRAF_Image_Converter
{
    const NONCE_ACTION = 'mraf_converter';
    const DEBUG_KEY = 'mraf_converter_debug';
    const BACKUP_DIR = 'mraf-backups';

    private $image_library = null;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX endpoints
        add_action('wp_ajax_mraf_convert_single_image', [$this, 'ajax_convert_single_image']);
        add_action('wp_ajax_mraf_get_converter_debug', [$this, 'ajax_get_debug_log']);
        add_action('wp_ajax_mraf_search_converter_images', [$this, 'ajax_search_images']);

        // Detect available image library
        $this->detect_image_library();
    }

    /**
     * Detect which image processing library is available
     */
    private function detect_image_library()
    {
        $imagick_available = extension_loaded('imagick') && class_exists('Imagick');
        $gd_available = extension_loaded('gd') && function_exists('gd_info');

        $imagick_webp = $imagick_available ? $this->is_imagick_webp_supported() : false;
        $gd_webp = $gd_available ? $this->is_gd_webp_supported() : false;

        if ($imagick_available && $imagick_webp) {
            $this->image_library = 'imagick';
        } elseif ($gd_available && $gd_webp) {
            $this->image_library = 'gd';
        } elseif ($imagick_available) {
            $this->image_library = 'imagick';
        } elseif ($gd_available) {
            $this->image_library = 'gd';
        } else {
            $this->image_library = null;
        }

        $this->log_debug('Image library detected: ' . ($this->image_library ?: 'none'));
    }

    /**
     * Get current image library
     */
    public function get_image_library()
    {
        return $this->image_library;
    }

    /**
     * Check if WebP conversion is supported
     */
    public function is_webp_supported()
    {
        if ($this->image_library === 'imagick') {
            return $this->is_imagick_webp_supported();
        } elseif ($this->image_library === 'gd') {
            return $this->is_gd_webp_supported();
        }
        return false;
    }

    /**
     * Check if Imagick supports WebP format
     */
    private function is_imagick_webp_supported()
    {
        try {
            $imagick = new Imagick();
            return in_array('WEBP', $imagick->queryFormats('WEBP'), true);
        } catch (Exception $e) {
            $this->log_debug('Imagick WebP check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if GD supports WebP format
     */
    private function is_gd_webp_supported()
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        $gd_info = gd_info();
        return !empty($gd_info['WebP Support']);
    }

    /**
     * Get detailed library capabilities
     */
    public function get_library_capabilities()
    {
        $capabilities = [
            'library' => $this->image_library,
            'webp_support' => false,
            'formats_supported' => [],
            'max_dimensions' => null,
            'version' => null,
        ];

        if ($this->image_library === 'imagick') {
            $imagick = new Imagick();
            $capabilities['webp_support'] = in_array('WEBP', $imagick->queryFormats());
            $capabilities['formats_supported'] = $imagick->queryFormats();
            $capabilities['version'] = Imagick::getVersion()['versionString'];

            // Get resource limits
            $capabilities['max_dimensions'] = [
                'width' => Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH),
                'height' => Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT)
            ];
        } elseif ($this->image_library === 'gd') {
            $gd_info = gd_info();
            $capabilities['webp_support'] = !empty($gd_info['WebP Support']);
            $capabilities['version'] = $gd_info['GD Version'];

            // Determine supported formats
            $formats = [];
            if (!empty($gd_info['JPEG Support'])) $formats[] = 'JPEG';
            if (!empty($gd_info['PNG Support'])) $formats[] = 'PNG';
            if (!empty($gd_info['GIF Read Support']) || !empty($gd_info['GIF Create Support'])) $formats[] = 'GIF';
            if (!empty($gd_info['WebP Support'])) $formats[] = 'WEBP';

            $capabilities['formats_supported'] = $formats;

            // Get memory limit based on PHP settings
            $memory_limit = ini_get('memory_limit');
            $capabilities['memory_limit'] = $memory_limit;
        }

        return $capabilities;
    }

    /**
     * Check if server meets minimum requirements
     */
    public function check_server_requirements()
    {
        $requirements = [
            'has_image_library' => false,
            'webp_supported' => false,
            'sufficient_memory' => false,
            'errors' => [],
            'warnings' => []
        ];

        // Check image library
        if (!$this->image_library) {
            $requirements['errors'][] = __('No image processing library found. Please install GD or Imagick extension.', 'mraf');
            return $requirements;
        }
        $requirements['has_image_library'] = true;

        // Check WebP support
        if (!$this->is_webp_supported()) {
            $requirements['errors'][] = __('WebP format is not supported by your image library.', 'mraf');
        } else {
            $requirements['webp_supported'] = true;
        }

        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_in_bytes = $this->convert_to_bytes($memory_limit);

        if ($memory_in_bytes < 128 * 1024 * 1024) { // Less than 128MB
            $requirements['warnings'][] = sprintf(
                __('Memory limit is %s. Large images may fail. Recommended: 128M or higher.', 'mraf'),
                $memory_limit
            );
        } else {
            $requirements['sufficient_memory'] = true;
        }

        // Check execution time
        $max_execution = ini_get('max_execution_time');
        if ($max_execution > 0 && $max_execution < 60) {
            $requirements['warnings'][] = sprintf(
                __('Max execution time is %d seconds. Bulk operations may timeout. Recommended: 60+ seconds.', 'mraf'),
                $max_execution
            );
        }

        // Check file upload size
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        $requirements['upload_max_filesize'] = $upload_max;
        $requirements['post_max_size'] = $post_max;

        return $requirements;
    }

    /**
     * Convert memory string to bytes
     */
    private function convert_to_bytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Validate image file
     */
    public function validate_image_file($file_path)
    {
        $errors = [];

        // Check if file exists
        if (!file_exists($file_path)) {
            $errors[] = __('File does not exist.', 'mraf');
            return $errors;
        }

        // Check if file is readable
        if (!is_readable($file_path)) {
            $errors[] = __('File is not readable.', 'mraf');
            return $errors;
        }

        // Check mime type
        // Some servers report alternate MIME variants (e.g., IE/legacy).
        $allowed_types = [
            'image/jpeg',
            'image/jpg',
            'image/pjpeg',     // progressive JPEG
            'image/png',
            'image/x-png',     // legacy PNG
            'image/x-citrix-png',
            'image/x-citrix-jpeg',
            'image/gif',
            'image/webp',
            'image/avif'
        ];

        $actual_mime = mime_content_type($file_path);
        if (!in_array($actual_mime, $allowed_types)) {
            $errors[] = sprintf(
                __('Invalid file type: %s. Allowed types: JPG, PNG, GIF, WebP.', 'mraf'),
                $actual_mime
            );
        }

        // Check file size
        $file_size = filesize($file_path);
        $max_size = $this->convert_to_bytes(ini_get('upload_max_filesize'));

        if ($file_size > $max_size) {
            $errors[] = sprintf(
                __('File size (%s) exceeds maximum allowed size (%s).', 'mraf'),
                size_format($file_size),
                size_format($max_size)
            );
        }

        // Try to get image dimensions
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            $errors[] = __('Could not read image dimensions. File may be corrupted.', 'mraf');
        }

        return $errors;
    }

    /**
     * Resize image to maximum width while maintaining aspect ratio
     *
     * @param string $source_path Path to source image
     * @param int $max_width Maximum width in pixels
     * @param string $output_path Path to save resized image (if null, overwrites source)
     * @return array Success status and info
     */
    public function resize_to_max_width($source_path, $max_width, $output_path = null)
    {
        $result = [
            'success' => false,
            'message' => '',
            'original_dimensions' => null,
            'new_dimensions' => null,
            'output_path' => null
        ];

        // Validate source file
        $validation_errors = $this->validate_image_file($source_path);
        if (!empty($validation_errors)) {
            $result['message'] = implode(' ', $validation_errors);
            return $result;
        }

        // Get original dimensions
        list($original_width, $original_height, $image_type) = getimagesize($source_path);
        $result['original_dimensions'] = [
            'width' => $original_width,
            'height' => $original_height
        ];

        // Check if resizing is needed
        if ($original_width <= $max_width) {
            $result['success'] = true;
            $result['message'] = __('Image width is already smaller than or equal to maximum width.', 'mraf');
            $result['new_dimensions'] = $result['original_dimensions'];
            $result['output_path'] = $source_path;
            return $result;
        }

        // Calculate new dimensions maintaining aspect ratio
        $new_width = $max_width;
        $new_height = intval(($original_height / $original_width) * $new_width);

        $result['new_dimensions'] = [
            'width' => $new_width,
            'height' => $new_height
        ];

        // Set output path
        if ($output_path === null) {
            $path_info = pathinfo($source_path);
            $suffix = '-resized-' . uniqid();
            $output_path = $path_info['dirname'] . '/' . $path_info['filename'] . $suffix . '.' . $path_info['extension'];
        }

        // Resize using available library
        if ($this->image_library === 'imagick') {
            $resize_result = $this->resize_with_imagick($source_path, $new_width, $new_height, $output_path);
        } elseif ($this->image_library === 'gd') {
            $resize_result = $this->resize_with_gd($source_path, $new_width, $new_height, $output_path, $image_type);
        } else {
            $result['message'] = __('No image processing library available.', 'mraf');
            return $result;
        }

        if ($resize_result['success']) {
            $result['success'] = true;
            $result['message'] = sprintf(
                __('Image resized from %dx%d to %dx%d', 'mraf'),
                $original_width,
                $original_height,
                $new_width,
                $new_height
            );
            $result['output_path'] = $output_path;
            $this->log_debug('Image resized successfully', $result);
        } else {
            $result['message'] = $resize_result['message'];
            $this->log_debug('Image resize failed', $result);
        }

        return $result;
    }

    /**
     * Resize image using Imagick
     */
    private function resize_with_imagick($source_path, $new_width, $new_height, $output_path)
    {
        try {
            $imagick = new Imagick($source_path);
            $imagick->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);
            $imagick->stripImage(); // Remove metadata to reduce file size
            $imagick->writeImage($output_path);
            $imagick->clear();
            $imagick->destroy();

            return [
                'success' => true,
                'message' => __('Resized with Imagick', 'mraf')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(__('Imagick error: %s', 'mraf'), $e->getMessage())
            ];
        }
    }

    /**
     * Resize image using GD
     */
    private function resize_with_gd($source_path, $new_width, $new_height, $output_path, $image_type)
    {
        // Create source image resource based on type
        switch ($image_type) {
            case IMAGETYPE_JPEG:
                $source_image = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source_image = imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $source_image = imagecreatefromgif($source_path);
                break;
            case IMAGETYPE_WEBP:
                $source_image = imagecreatefromwebp($source_path);
                break;
            case defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : 9999:
                if (function_exists('imagecreatefromavif')) {
                    $source_image = imagecreatefromavif($source_path);
                } else {
                    return [
                        'success' => false,
                        'message' => __('AVIF not supported by GD on this server', 'mraf')
                    ];
                }
                break;
            default:
                return [
                    'success' => false,
                    'message' => __('Unsupported image type for GD', 'mraf')
                ];
        }

        if (!$source_image) {
            return [
                'success' => false,
                'message' => __('Failed to create image resource', 'mraf')
            ];
        }

        // Create new image
        $new_image = imagecreatetruecolor($new_width, $new_height);

        // Preserve transparency for PNG and GIF
        if ($image_type === IMAGETYPE_PNG || $image_type === IMAGETYPE_GIF || $image_type === IMAGETYPE_WEBP) {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }

        // Resize
        $original_width = imagesx($source_image);
        $original_height = imagesy($source_image);
        imagecopyresampled(
            $new_image,
            $source_image,
            0,
            0,
            0,
            0,
            $new_width,
            $new_height,
            $original_width,
            $original_height
        );

        // Save based on original type
        $save_success = false;
        switch ($image_type) {
            case IMAGETYPE_JPEG:
                $save_success = imagejpeg($new_image, $output_path, 90);
                break;
            case IMAGETYPE_PNG:
                $save_success = imagepng($new_image, $output_path, 9);
                break;
            case IMAGETYPE_GIF:
                $save_success = imagegif($new_image, $output_path);
                break;
            case IMAGETYPE_WEBP:
                $save_success = imagewebp($new_image, $output_path, 90);
                break;
        }

        // Free memory
        imagedestroy($source_image);
        imagedestroy($new_image);

        return [
            'success' => $save_success,
            'message' => $save_success ? __('Resized with GD', 'mraf') : __('Failed to save resized image', 'mraf')
        ];
    }

    /**
     * Convert image to WebP format with quality control
     *
     * @param string $source_path Path to source image
     * @param int $quality Quality 1-100 (default 85)
     * @param string $output_path Path to save WebP (if null, replaces source extension)
     * @return array Success status and info
     */
    public function convert_to_webp($source_path, $quality = 85, $output_path = null)
    {
        $this->log_debug('=== Starting WebP Conversion ===');
        $this->log_debug('Source file: ' . basename($source_path));

        $result = [
            'success' => false,
            'message' => '',
            'source_path' => $source_path,
            'output_path' => null,
            'original_format' => null,
            'file_size_before' => 0,
            'file_size_after' => 0,
            'compression_ratio' => 0
        ];

        // Check WebP support
        if (!$this->is_webp_supported()) {
            $this->log_debug('ERROR: WebP not supported', [
                'library' => $this->image_library
            ]);
            $result['message'] = __('WebP conversion is not supported on this server.', 'mraf');
            return $result;
        }

        $this->log_debug('WebP support confirmed', ['library' => $this->image_library]);

        // Validate source file
        $validation_errors = $this->validate_image_file($source_path);
        if (!empty($validation_errors)) {
            $this->log_debug('ERROR: File validation failed', ['errors' => $validation_errors]);
            $result['message'] = implode(' ', $validation_errors);
            return $result;
        }

        $this->log_debug('File validation passed');

        // Get original file info
        $result['file_size_before'] = filesize($source_path);
        $image_info = @getimagesize($source_path);

        if ($image_info === false) {
            $this->log_debug('ERROR: Failed to get image info');
            $result['message'] = __('Failed to read image information', 'mraf');
            return $result;
        }

        $result['original_format'] = image_type_to_mime_type($image_info[2]);

        $this->log_debug('Image info retrieved', [
            'format' => $result['original_format'],
            'dimensions' => $image_info[0] . 'x' . $image_info[1],
            'size' => size_format($result['file_size_before'])
        ]);

        // Validate quality
        $quality = max(1, min(100, intval($quality)));
        $this->log_debug('Quality setting: ' . $quality);

        // Set output path
        if ($output_path === null) {
            $path_info = pathinfo($source_path);
            $output_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
        }

        $this->log_debug('Output path: ' . basename($output_path));

        // Convert using available library
        if ($this->image_library === 'imagick') {
            $this->log_debug('Using Imagick for conversion');
            $convert_result = $this->convert_to_webp_imagick($source_path, $quality, $output_path);
        } elseif ($this->image_library === 'gd') {
            $this->log_debug('Using GD for conversion');
            $convert_result = $this->convert_to_webp_gd($source_path, $quality, $output_path, $image_info[2]);
        } else {
            $this->log_debug('ERROR: No image library available');
            $result['message'] = __('No image processing library available.', 'mraf');
            return $result;
        }

        if ($convert_result['success']) {
            $result['success'] = true;
            $result['output_path'] = $output_path;
            $result['file_size_after'] = file_exists($output_path) ? filesize($output_path) : 0;

            if ($result['file_size_before'] > 0) {
                $result['compression_ratio'] = round(
                    (1 - ($result['file_size_after'] / $result['file_size_before'])) * 100,
                    2
                );
            }

            $result['message'] = sprintf(
                __('Converted to WebP. Size reduced by %s%% (from %s to %s)', 'mraf'),
                $result['compression_ratio'],
                size_format($result['file_size_before']),
                size_format($result['file_size_after'])
            );

            $this->log_debug('Image converted to WebP successfully', [
                'source' => basename($source_path),
                'output' => basename($output_path),
                'quality' => $quality,
                'compression_ratio' => $result['compression_ratio'] . '%'
            ]);
        } else {
            $result['message'] = $convert_result['message'];
            $this->log_debug('WebP conversion failed', ['error' => $convert_result['message']]);
        }

        return $result;
    }

    /**
     * Convert to WebP using Imagick
     */
    private function convert_to_webp_imagick($source_path, $quality, $output_path)
    {
        $this->log_debug('Starting Imagick WebP conversion', [
            'source' => basename($source_path),
            'output' => basename($output_path),
            'quality' => $quality
        ]);

        try {
            $imagick = new Imagick($source_path);
            $this->log_debug('Imagick object created successfully');

            // Get original format for logging
            $original_format = $imagick->getImageFormat();
            $this->log_debug('Original format: ' . $original_format);

            $imagick->setImageFormat('webp');
            $this->log_debug('Image format set to WebP');

            $imagick->setImageCompressionQuality($quality);
            $this->log_debug('Compression quality set: ' . $quality);

            $imagick->stripImage(); // Remove metadata
            $this->log_debug('Image metadata stripped');

            $imagick->writeImage($output_path);
            $this->log_debug('Image written to disk');

            // Verify output file
            if (file_exists($output_path)) {
                $file_size = filesize($output_path);
                $this->log_debug('WebP conversion successful', [
                    'output_file' => basename($output_path),
                    'size' => size_format($file_size)
                ]);
            } else {
                $this->log_debug('ERROR: Output file not created');
            }

            $imagick->clear();
            $imagick->destroy();

            return [
                'success' => true,
                'message' => __('Converted to WebP with Imagick', 'mraf')
            ];
        } catch (Exception $e) {
            $this->log_debug('ERROR: Imagick exception', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return [
                'success' => false,
                'message' => sprintf(__('Imagick error: %s', 'mraf'), $e->getMessage())
            ];
        }
    }

    /**
     * Convert to WebP using GD
     */
    private function convert_to_webp_gd($source_path, $quality, $output_path, $image_type)
    {
        $this->log_debug('Starting GD WebP conversion', [
            'source' => basename($source_path),
            'output' => basename($output_path),
            'quality' => $quality,
            'image_type' => $image_type
        ]);

        // Check if imagewebp function exists
        if (!function_exists('imagewebp')) {
            $this->log_debug('ERROR: imagewebp function not available');
            return [
                'success' => false,
                'message' => __('imagewebp function not available on this server', 'mraf')
            ];
        }

        // Create source image resource
        $source_image = null;
        switch ($image_type) {
            case IMAGETYPE_JPEG:
                $this->log_debug('Creating JPEG image resource');
                $source_image = @imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $this->log_debug('Creating PNG image resource');
                $source_image = @imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $this->log_debug('Creating GIF image resource');
                $source_image = @imagecreatefromgif($source_path);
                break;
            case IMAGETYPE_WEBP:
                $this->log_debug('Creating WebP image resource');
                $source_image = @imagecreatefromwebp($source_path);
                break;
            case defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : 9999:
                if (function_exists('imagecreatefromavif')) {
                    $this->log_debug('Creating AVIF image resource');
                    $source_image = @imagecreatefromavif($source_path);
                } else {
                    $this->log_debug('ERROR: AVIF not supported by GD');
                    return [
                        'success' => false,
                        'message' => __('AVIF not supported by GD on this server', 'mraf')
                    ];
                }
                break;
            default:
                $this->log_debug('ERROR: Unsupported image type: ' . $image_type);
                return [
                    'success' => false,
                    'message' => __('Unsupported source image type', 'mraf')
                ];
        }

        if (!$source_image) {
            $error = error_get_last();
            $this->log_debug('ERROR: Failed to create image resource', ['error' => $error]);
            return [
                'success' => false,
                'message' => __('Failed to create image resource from source. File may be corrupted.', 'mraf')
            ];
        }

        $this->log_debug('Image resource created successfully');

        // Get image dimensions for logging
        $width = imagesx($source_image);
        $height = imagesy($source_image);
        $this->log_debug('Image dimensions: ' . $width . 'x' . $height);

        // Preserve transparency - CRITICAL FIX for PNG
        // Use FALSE for alphablending when converting FROM formats with transparency
        imagealphablending($source_image, false);
        imagesavealpha($source_image, true);
        $this->log_debug('Transparency preservation enabled');

        // Check output directory is writable
        $output_dir = dirname($output_path);
        if (!is_writable($output_dir)) {
            $this->log_debug('ERROR: Output directory not writable: ' . $output_dir);
            imagedestroy($source_image);
            return [
                'success' => false,
                'message' => __('Output directory is not writable. Check permissions.', 'mraf')
            ];
        }

        // WORKAROUND: For PNG with transparency on Windows/GD, create a clean image
        // This fixes the imagewebp() hanging issue
        if ($image_type === IMAGETYPE_PNG) {
            $this->log_debug('Applying PNG transparency workaround for GD');
            $clean_image = imagecreatetruecolor($width, $height);
            imagealphablending($clean_image, false);
            imagesavealpha($clean_image, true);

            // Fill with transparent background
            $transparent = imagecolorallocatealpha($clean_image, 0, 0, 0, 127);
            imagefilledrectangle($clean_image, 0, 0, $width, $height, $transparent);

            // Copy the original image onto the clean one
            imagecopy($clean_image, $source_image, 0, 0, 0, 0, $width, $height);
            imagedestroy($source_image);
            $source_image = $clean_image;
            $this->log_debug('PNG transparency workaround applied');
        }

        // Convert to WebP
        $this->log_debug('Converting to WebP format (this may take a few seconds)...');

        // Set a custom error handler to catch warnings
        set_error_handler(function($errno, $errstr) {
            $this->log_debug('PHP Warning during conversion: ' . $errstr);
        });

        $success = @imagewebp($source_image, $output_path, $quality);

        // Restore error handler
        restore_error_handler();

        if (!$success) {
            $error = error_get_last();
            $this->log_debug('ERROR: imagewebp() returned false', ['error' => $error]);
        } else {
            $this->log_debug('imagewebp() completed successfully');
        }

        // Verify file was actually created and has content
        if ($success && file_exists($output_path)) {
            $file_size = filesize($output_path);
            if ($file_size > 0) {
                $this->log_debug('WebP conversion successful', [
                    'output_file' => basename($output_path),
                    'size' => size_format($file_size)
                ]);
                $success = true;
            } else {
                $this->log_debug('ERROR: Output file created but has zero size');
                $success = false;
                @unlink($output_path); // Remove empty file
            }
        } else {
            $this->log_debug('ERROR: Output file not created or imagewebp failed');
            $success = false;
        }

        // Free memory
        imagedestroy($source_image);

        return [
            'success' => $success,
            'message' => $success
                ? __('Converted to WebP with GD', 'mraf')
                : __('Failed to save WebP image. Check debug log for details.', 'mraf')
        ];
    }

    /**
     * Sanitize filename/title for URL-safe usage
     *
     * @param string $title The title to sanitize
     * @return string Sanitized filename
     */
    public function sanitize_filename($title)
    {
        // Remove special characters and convert to lowercase
        $title = strtolower($title);

        // Replace spaces and underscores with hyphens
        $title = str_replace(['_', ' '], '-', $title);

        // Remove any character that isn't alphanumeric, hyphen, or dot
        $title = preg_replace('/[^a-z0-9\-\.]/', '', $title);

        // Remove multiple consecutive hyphens
        $title = preg_replace('/-+/', '-', $title);

        // Remove multiple consecutive dots
        $title = preg_replace('/\.+/', '.', $title);

        // Trim hyphens and dots from start and end
        $title = trim($title, '-.');

        // If empty after sanitization, generate a default name
        if (empty($title)) {
            $title = 'image-' . time();
        }

        return $title;
    }

    /**
     * Rename and move file with new title
     *
     * @param string $source_path Current file path
     * @param string $new_title New title (will be sanitized)
     * @param string $new_extension New file extension (optional, defaults to current)
     * @return array Success status and new path
     */
    public function rename_file($source_path, $new_title, $new_extension = null)
    {
        $result = [
            'success' => false,
            'message' => '',
            'old_path' => $source_path,
            'new_path' => null,
            'new_filename' => null
        ];

        // Validate source file exists
        if (!file_exists($source_path)) {
            $result['message'] = __('Source file does not exist.', 'mraf');
            return $result;
        }

        // Get source file info
        $path_info = pathinfo($source_path);

        // Determine extension
        if ($new_extension === null) {
            $extension = $path_info['extension'];
        } else {
            $extension = ltrim($new_extension, '.');
        }

        // Sanitize new title
        $sanitized_title = $this->sanitize_filename($new_title);

        // Create new filename
        $new_filename = $sanitized_title . '.' . $extension;
        $new_path = $path_info['dirname'] . '/' . $new_filename;

        // Check if target already exists
        if ($new_path !== $source_path && file_exists($new_path)) {
            // Add unique suffix
            $counter = 1;
            do {
                $new_filename = $sanitized_title . '-' . $counter . '.' . $extension;
                $new_path = $path_info['dirname'] . '/' . $new_filename;
                $counter++;
            } while (file_exists($new_path) && $counter < 100);

            if ($counter >= 100) {
                $result['message'] = __('Could not generate unique filename.', 'mraf');
                return $result;
            }
        }

        // Rename file
        if ($source_path === $new_path) {
            // No rename needed
            $result['success'] = true;
            $result['new_path'] = $new_path;
            $result['new_filename'] = $new_filename;
            $result['message'] = __('Filename unchanged.', 'mraf');
        } else {
            if (rename($source_path, $new_path)) {
                $result['success'] = true;
                $result['new_path'] = $new_path;
                $result['new_filename'] = $new_filename;
                $result['message'] = sprintf(
                    __('File renamed from %s to %s', 'mraf'),
                    basename($source_path),
                    $new_filename
                );
                $this->log_debug('File renamed successfully', [
                    'from' => basename($source_path),
                    'to' => $new_filename
                ]);
            } else {
                $result['message'] = __('Failed to rename file. Check file permissions.', 'mraf');
                $this->log_debug('File rename failed', [
                    'from' => $source_path,
                    'to' => $new_path
                ]);
            }
        }

        return $result;
    }

    /**
     * Update WordPress attachment metadata after conversion
     *
     * @param int $attachment_id WordPress attachment ID
     * @param string $new_file_path New file path
     * @param array $options Additional options (title, alt, caption, etc.)
     * @return array Success status and info
     */
    public function update_attachment_metadata($attachment_id, $new_file_path, $options = [])
    {
        $result = [
            'success' => false,
            'message' => '',
            'attachment_id' => $attachment_id
        ];

        // Validate attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            $result['message'] = __('Attachment not found.', 'mraf');
            return $result;
        }

        // Validate new file exists
        if (!file_exists($new_file_path)) {
            $result['message'] = __('New file does not exist.', 'mraf');
            return $result;
        }

        // Update attached file path
        update_attached_file($attachment_id, $new_file_path);

        // Generate new metadata
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $new_file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);

        // Update post title if provided
        if (!empty($options['title'])) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_title' => sanitize_text_field($options['title'])
            ]);
        }

        // Update alt text if provided
        if (!empty($options['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($options['alt']));
        }

        // Update caption if provided
        if (!empty($options['caption'])) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_excerpt' => sanitize_text_field($options['caption'])
            ]);
        }

        // Update description if provided
        if (!empty($options['description'])) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_content' => wp_kses_post($options['description'])
            ]);
        }

        // Update MIME type
        $file_type = wp_check_filetype(basename($new_file_path));
        if ($file_type['type']) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_mime_type' => $file_type['type']
            ]);
        }

        $result['success'] = true;
        $result['message'] = __('Attachment metadata updated successfully.', 'mraf');
        $result['metadata'] = $attachment_metadata;

        $this->log_debug('Attachment metadata updated', [
            'id' => $attachment_id,
            'file' => basename($new_file_path)
        ]);

        return $result;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_media_page(
            __('Convert & Replace Images', 'mraf'),
            __('Convert & Replace', 'mraf'),
            'upload_files',
            'mraf-image-converter',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        if ($hook !== 'media_page_mraf-image-converter') {
            return;
        }

        wp_enqueue_media();
        $shared_js_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-shared.js') ?: MRAF_VERSION;
        $converter_js_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/js/mraf-converter.js') ?: MRAF_VERSION;
        $shared_css_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-shared.css') ?: MRAF_VERSION;
        $gallery_css_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-gallery.css') ?: MRAF_VERSION;
        $converter_css_version = @filemtime(MRAF_PLUGIN_DIR . 'admin/css/mraf-converter.css') ?: MRAF_VERSION;

        // Enqueue shared utilities first
        wp_enqueue_script(
            'mraf-shared',
            MRAF_PLUGIN_URL . 'admin/js/mraf-shared.js',
            ['jquery'],
            $shared_js_version,
            true
        );

        wp_enqueue_script(
            'mraf-converter',
            MRAF_PLUGIN_URL . 'admin/js/mraf-converter.js',
            ['jquery', 'jquery-ui-slider', 'mraf-shared'],
            $converter_js_version,
            true
        );

        // Enqueue shared styles (CSS variables, buttons, tabs, filters, etc.)
        wp_enqueue_style(
            'mraf-shared',
            MRAF_PLUGIN_URL . 'admin/css/mraf-shared.css',
            [],
            $shared_css_version
        );

        // Enqueue shared gallery styles
        wp_enqueue_style(
            'mraf-gallery',
            MRAF_PLUGIN_URL . 'admin/css/mraf-gallery.css',
            ['mraf-shared'],
            $gallery_css_version
        );

        // Enqueue converter-specific styles
        wp_enqueue_style(
            'mraf-converter',
            MRAF_PLUGIN_URL . 'admin/css/mraf-converter.css',
            ['mraf-shared', 'mraf-gallery'],
            $converter_css_version
        );

        // Localize script
        wp_localize_script('mraf-converter', 'mrafConverterData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'imageLibrary' => $this->image_library,
            'webpSupported' => $this->is_webp_supported(),
            'strings' => [
                'uploadError' => __('Upload failed. Please try again.', 'mraf'),
                'convertSuccess' => __('Image converted and replaced successfully!', 'mraf'),
                'convertError' => __('Failed to convert image.', 'mraf'),
                'confirmConvertAll' => __('Are you sure you want to convert and replace all images? The originals will be replaced with WebP versions.', 'mraf'),
                'noLibrary' => __('No image processing library detected. Please install GD or Imagick.', 'mraf'),
                'noWebpSupport' => __('WebP conversion is not supported on your server.', 'mraf'),
            ]
        ]);
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        $requirements = $this->check_server_requirements();
        $capabilities = $this->get_library_capabilities();
        ?>
        <div class="wrap mraf-converter-page" id="mraf-converter-wrap">
            <h1><?php echo esc_html__('Convert & Replace Images', 'mraf'); ?></h1>
            <p class="description">
                <?php echo esc_html__('Convert images to WebP format and replace them in-place. The converted images will keep the same attachment ID, so they appear everywhere the originals were used.', 'mraf'); ?>
            </p>

            <?php if (!empty($requirements['errors'])): ?>
                <div class="notice notice-error">
                    <p><strong><?php echo esc_html__('System Requirements Not Met:', 'mraf'); ?></strong></p>
                    <ul>
                        <?php foreach ($requirements['errors'] as $error): ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($requirements['warnings'])): ?>
                <div class="notice notice-warning">
                    <p><strong><?php echo esc_html__('Warnings:', 'mraf'); ?></strong></p>
                    <ul>
                        <?php foreach ($requirements['warnings'] as $warning): ?>
                            <li><?php echo esc_html($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div id="mraf-converter-notices"></div>

            <!-- Tabs -->
            <div class="mraf-bulk-tabs">
                <button class="mraf-tab-btn active" data-tab="manual">
                    <?php echo esc_html__('Media Library', 'mraf'); ?>
                    <span class="mraf-tab-badge" id="mraf-manual-count" style="display:none;">0</span>
                </button>
                <button class="mraf-tab-btn" data-tab="search">
                    <?php echo esc_html__('Search & Filter', 'mraf'); ?>
                    <span class="mraf-tab-badge" id="mraf-search-count" style="display:none;">0</span>
                </button>
                <button class="mraf-tab-btn" data-tab="debug">
                    <?php echo esc_html__('Debug Log', 'mraf'); ?>
                </button>
                <button class="mraf-tab-btn" data-tab="settings">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php echo esc_html__('Settings', 'mraf'); ?>
                </button>
            </div>

            <!-- Tab Content: Media Library -->
            <div class="mraf-tab-content active" data-tab="manual">
                <!-- Upload Area -->
                <div class="mraf-upload-area">
                    <h2><?php echo esc_html__('Select Images', 'mraf'); ?></h2>
                    <p class="description" style="margin-bottom: 16px;">
                        <?php echo esc_html__('Choose images from your Media Library to convert to WebP format.', 'mraf'); ?>
                    </p>
                    <button type="button" class="mraf-btn mraf-btn-primary mraf-select-images-btn" id="mraf-select-files">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php echo esc_html__('Select Images from Media Library', 'mraf'); ?>
                    </button>
                    <p class="description" style="margin-top: 12px; font-size: 12px; color: var(--mraf-muted-fg);">
                        <?php echo esc_html__('Supported formats: JPG, PNG, GIF, WebP', 'mraf'); ?>
                    </p>
                </div>

                <!-- Images List -->
                <div class="mraf-images-section" id="mraf-images-section" style="display: none;">
                    <div class="mraf-section-header">
                        <h2><?php echo esc_html__('Selected Images', 'mraf'); ?> <span id="mraf-image-count">0</span></h2>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="mraf-view-toggle">
                                <button type="button" id="mraf-view-grid" class="active" title="<?php echo esc_attr__('Grid View', 'mraf'); ?>">
                                    <span class="dashicons dashicons-grid-view"></span>
                                </button>
                                <button type="button" id="mraf-view-list" title="<?php echo esc_attr__('List View', 'mraf'); ?>">
                                    <span class="dashicons dashicons-list-view"></span>
                                </button>
                            </div>
                            <div class="mraf-actions">
                                <button type="button" class="button button-secondary" id="mraf-clear-all">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <?php echo esc_html__('Clear All', 'mraf'); ?>
                                </button>
                                <button type="button" class="button button-primary" id="mraf-convert-all">
                                    <span class="dashicons dashicons-image-rotate"></span>
                                    <?php echo esc_html__('Convert All', 'mraf'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mraf-options-bar">
                        <label>
                            <input type="checkbox" id="mraf-keep-basename" />
                            <?php echo esc_html__('Keep original filenames (only change extension)', 'mraf'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="mraf-update-refs" checked />
                            <?php echo esc_html__('Update references in content', 'mraf'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="mraf-create-backup" checked />
                            <?php echo esc_html__('Create backups before replacing', 'mraf'); ?>
                        </label>
                    </div>

                    <div id="mraf-images-list" class="mraf-images-grid">
                        <!-- Images will be dynamically added here -->
                    </div>

                    <div class="mraf-progress-section" id="mraf-progress-section" style="display: none;">
                        <div class="mraf-progress-bar">
                            <div class="mraf-progress-fill" id="mraf-progress-fill"></div>
                        </div>
                        <p class="mraf-progress-text" id="mraf-progress-text">
                            <?php echo esc_html__('Processing...', 'mraf'); ?>
                        </p>
                    </div>
                    </div>

            </div>

            <!-- Tab Content: Search & Filter -->
            <div class="mraf-tab-content" data-tab="search">
                <div class="mraf-search-box">
                    <h3><?php echo esc_html__('Find Images to Convert', 'mraf'); ?></h3>
                    <p class="description"><?php echo esc_html__('Search for images in your media library and add them to the conversion queue.', 'mraf'); ?></p>

                    <div class="mraf-search-filters">
                        <input type="text" id="mraf-search-query" placeholder="<?php echo esc_attr__('Search by filename, title, or alt text...', 'mraf'); ?>" class="mraf-input">
                        <select id="mraf-search-type" class="mraf-select">
                            <option value=""><?php echo esc_html__('All Types', 'mraf'); ?></option>
                            <option value="image/jpeg"><?php echo esc_html__('JPG / JPEG', 'mraf'); ?></option>
                            <option value="image/png"><?php echo esc_html__('PNG', 'mraf'); ?></option>
                            <option value="image/gif"><?php echo esc_html__('GIF', 'mraf'); ?></option>
                            <option value="image/avif"><?php echo esc_html__('AVIF', 'mraf'); ?></option>
                        </select>
                        <select id="mraf-search-date" class="mraf-select">
                            <option value=""><?php echo esc_html__('All Dates', 'mraf'); ?></option>
                            <option value="today"><?php echo esc_html__('Today', 'mraf'); ?></option>
                            <option value="week"><?php echo esc_html__('This Week', 'mraf'); ?></option>
                            <option value="month"><?php echo esc_html__('This Month', 'mraf'); ?></option>
                            <option value="year"><?php echo esc_html__('This Year', 'mraf'); ?></option>
                        </select>
                        <button type="button" class="mraf-btn mraf-btn-primary" id="mraf-search-btn">
                            <span class="dashicons dashicons-search"></span>
                            <?php echo esc_html__('Search', 'mraf'); ?>
                        </button>
                    </div>

                    <!-- Advanced Filtering Options -->
                    <div class="mraf-advanced-filters">
                        <label class="mraf-filter-checkbox">
                            <input type="checkbox" id="mraf-filter-unconverted">
                            <span class="mraf-checkbox-custom"></span>
                            <span class="mraf-checkbox-label"><?php echo esc_html__('Show unconverted images only', 'mraf'); ?></span>
                        </label>
                        <button type="button" class="mraf-btn mraf-btn-secondary mraf-select-all-btn" id="mraf-select-all-format">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html__('Select All Visible', 'mraf'); ?>
                        </button>
                    </div>
                    <div id="mraf-search-results" class="mraf-search-results"></div>
                </div>
            </div>

            <!-- Tab Content: Debug Log -->
            <div class="mraf-tab-content" data-tab="debug">
                <div class="mraf-debug-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0;"><?php echo esc_html__('Debug Log', 'mraf'); ?></h3>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-converter-refresh-log">
                                <span class="dashicons dashicons-update"></span>
                                <?php echo esc_html__('Refresh', 'mraf'); ?>
                            </button>
                            <button type="button" class="mraf-btn mraf-btn-secondary" id="mraf-converter-clear-log">
                                <span class="dashicons dashicons-trash"></span>
                                <?php echo esc_html__('Clear Log', 'mraf'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="mraf-converter-debug-log"
                        style="font-family:monospace;white-space:pre-wrap;background:#f8f9fa;border:1px solid #ddd;padding:16px;border-radius:4px;max-height:600px;overflow-y:auto;">
                        <?php
                        $dbg = get_transient(self::DEBUG_KEY);
                        if ($dbg && is_array($dbg)) {
                            foreach ($dbg as $line) {
                                echo esc_html($line) . "\n";
                            }
                        } else {
                            echo esc_html__('No debug logs yet. Logs will appear here after conversion operations.', 'mraf');
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Settings -->
            <div class="mraf-tab-content" data-tab="settings">
                <!-- Settings Panel -->
                <div class="mraf-settings-panel">
                    <h2><?php echo esc_html__('Conversion Settings', 'mraf'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php echo esc_html__('Output Format', 'mraf'); ?></label>
                            </th>
                            <td>
                                <strong>WebP</strong>
                                <p class="description">
                                    <?php
                                    echo esc_html__('Library: ', 'mraf') . esc_html($capabilities['library'] ?: 'None');
                                    if ($capabilities['version']) {
                                        echo ' (' . esc_html($capabilities['version']) . ')';
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="mraf-quality"><?php echo esc_html__('Quality', 'mraf'); ?></label>
                            </th>
                            <td>
                                <div class="mraf-quality-control">
                                    <input type="range" id="mraf-quality-slider" min="1" max="100" value="85" step="1" />
                                    <input type="number" id="mraf-quality" min="1" max="100" value="85" />
                                    <span class="mraf-quality-label">85%</span>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__('Higher quality = larger file size. Recommended: 80-90%', 'mraf'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="mraf-max-width-enable"><?php echo esc_html__('Maximum Width', 'mraf'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="mraf-max-width-enable" />
                                    <?php echo esc_html__('Enable max width resizing', 'mraf'); ?>
                                </label>
                                <div class="mraf-max-width-control" style="margin-top: 10px;">
                                    <input type="number" id="mraf-max-width" min="100" max="5000" value="1920" placeholder="1920" />
                                    <span>px</span>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__('Images wider than this will be resized (maintains aspect ratio)', 'mraf'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php echo esc_html__('Options', 'mraf'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="mraf-delete-original" checked />
                                    <?php echo esc_html__('Delete original images after conversion', 'mraf'); ?>
                                </label>
                                <p class="description">
                                    <?php echo esc_html__('Images will be replaced in-place. The WebP version will replace the original attachment (same ID), so it appears everywhere the image is used.', 'mraf'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- System Info (Collapsible) -->
                <div class="mraf-system-info">
                    <h3>
                        <button type="button" class="mraf-toggle-section">
                            <span class="dashicons dashicons-arrow-right"></span>
                            <?php echo esc_html__('System Information', 'mraf'); ?>
                        </button>
                    </h3>
                    <div class="mraf-system-info-content" style="display: none;">
                        <table class="widefat">
                            <tr>
                                <td><?php echo esc_html__('Image Library', 'mraf'); ?></td>
                                <td><strong><?php echo esc_html($capabilities['library'] ?: 'None'); ?></strong></td>
                            </tr>
                            <tr>
                                <td><?php echo esc_html__('Library Version', 'mraf'); ?></td>
                                <td><?php echo esc_html($capabilities['version'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><?php echo esc_html__('WebP Support', 'mraf'); ?></td>
                                <td>
                                    <?php if ($capabilities['webp_support']): ?>
                                        <span style="color: green;">✓ <?php echo esc_html__('Supported', 'mraf'); ?></span>
                                    <?php else: ?>
                                        <span style="color: red;">✗ <?php echo esc_html__('Not Supported', 'mraf'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php echo esc_html__('Upload Max Filesize', 'mraf'); ?></td>
                                <td><?php echo esc_html($requirements['upload_max_filesize']); ?></td>
                            </tr>
                            <tr>
                                <td><?php echo esc_html__('Post Max Size', 'mraf'); ?></td>
                                <td><?php echo esc_html($requirements['post_max_size']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Convert single image
     */
    public function ajax_convert_single_image()
    {
        $this->log_debug('========================================');
        $this->log_debug('AJAX: Convert single image REQUEST RECEIVED');

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            $this->log_debug('ERROR: Permission denied for user');
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        // Get parameters
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        $new_title = isset($_POST['title']) ? trim(wp_unslash($_POST['title'])) : '';
        $quality = isset($_POST['quality']) ? intval($_POST['quality']) : 85;
        $max_width = isset($_POST['max_width']) ? intval($_POST['max_width']) : 0;
        $enable_resize = isset($_POST['enable_resize']) && $_POST['enable_resize'] === 'true';
        $delete_original = isset($_POST['delete_original']) && $_POST['delete_original'] === 'true';
        $keep_basename = !empty($_POST['keep_basename']) && $_POST['keep_basename'] !== 'false';
        $update_refs = !empty($_POST['update_refs']) && $_POST['update_refs'] !== 'false';
        $create_backup = !empty($_POST['create_backup']) && $_POST['create_backup'] !== 'false';

        $this->log_debug('Request parameters', [
            'attachment_id' => $attachment_id,
            'title' => $new_title,
            'quality' => $quality,
            'enable_resize' => $enable_resize ? 'yes' : 'no',
            'max_width' => $max_width,
            'delete_original' => $delete_original ? 'yes' : 'no',
            'keep_basename' => $keep_basename ? 'yes' : 'no',
            'create_backup' => $create_backup ? 'yes' : 'no'
        ]);

        // Validate attachment
        if (empty($attachment_id)) {
            $this->log_debug('ERROR: Invalid attachment ID');
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'mraf')]);
        }

        // Get attachment file path
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $this->log_debug('ERROR: Attachment file not found', [
                'attachment_id' => $attachment_id,
                'file_path' => $file_path
            ]);
            wp_send_json_error(['message' => __('Attachment file not found.', 'mraf')]);
        }

        $this->log_debug('Attachment file found', [
            'file_path' => $file_path,
            'file_size' => size_format(filesize($file_path)),
            'mime_type' => get_post_mime_type($attachment_id)
        ]);

        $old_url = wp_get_attachment_url($attachment_id);
        $old_base = pathinfo($file_path, PATHINFO_FILENAME);

        $conversion_log = [];
        $temp_file = $file_path;

        try {
            $this->log_debug('Starting conversion process...');
            if ($create_backup) {
                $this->create_backup($attachment_id);
                $conversion_log[] = __('Backup created before conversion.', 'mraf');
            }

            // Step 1: Resize if enabled
            if ($enable_resize && $max_width > 0) {
                $resize_result = $this->resize_to_max_width($temp_file, $max_width);
                $conversion_log[] = $resize_result['message'];

                if (!$resize_result['success']) {
                    throw new Exception($resize_result['message']);
                }

                $temp_file = $resize_result['output_path'];
            }

            // Step 2: Convert to WebP
            $webp_result = $this->convert_to_webp($temp_file, $quality);
            $conversion_log[] = $webp_result['message'];

            if (!$webp_result['success']) {
                throw new Exception($webp_result['message']);
            }

            $webp_path = $webp_result['output_path'];
            $initial_webp_path = $webp_path;

            // Step 3: Rename file if title provided
            $should_rename = !$keep_basename && !empty($new_title);
            if ($should_rename) {
                $rename_result = $this->rename_file($webp_path, $new_title, 'webp');
                $conversion_log[] = $rename_result['message'];

                if ($rename_result['success']) {
                    $webp_path = $rename_result['new_path'];
                    if ($initial_webp_path !== $webp_path && file_exists($initial_webp_path)) {
                        @unlink($initial_webp_path);
                    }
                }
            } else {
                // Keep original base name when requested
                $new_title = $keep_basename ? $old_base : pathinfo($webp_path, PATHINFO_FILENAME);
            }

            // Step 4: Delete original file (and sizes) if requested
            if ($delete_original && $webp_path !== $file_path) {
                $old_meta = wp_get_attachment_metadata($attachment_id);
                $delete_result = $this->delete_attachment_files($file_path, $old_meta);
                $conversion_log[] = $delete_result['message'];

                if (!$delete_result['success']) {
                    $this->log_debug('Failed to delete original file', ['file' => $file_path]);
                }
            }

            // Step 5: Update existing attachment to point to WebP file
            $attachment_result = $this->update_attachment_metadata($attachment_id, $webp_path, [
                'title' => !empty($new_title) ? $new_title : pathinfo($webp_path, PATHINFO_FILENAME),
                'alt' => !empty($new_title) ? $new_title : ''
            ]);

            if (!$attachment_result['success']) {
                throw new Exception($attachment_result['message']);
            }

            $new_url = wp_get_attachment_url($attachment_id);
            if ($update_refs && $old_url && $new_url && $old_url !== $new_url) {
                $stats = $this->update_url_references($old_url, $new_url);
                $conversion_log[] = sprintf(
                    __('Updated references in %d posts, %d meta rows, %d Elementor entries, %d builders, %d options, %d widgets.', 'mraf'),
                    $stats['posts'] ?? 0,
                    $stats['meta'] ?? 0,
                    $stats['elementor'] ?? 0,
                    $stats['builders'] ?? 0,
                    $stats['options'] ?? 0,
                    $stats['widgets'] ?? 0
                );
            }

            $conversion_log[] = __('Attachment updated successfully', 'mraf');
            if ($temp_file !== $file_path && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            // Mark as converted server-side for UI/filters
            update_post_meta($attachment_id, '_mraf_converted', current_time('timestamp'));

            $this->log_debug('✓ CONVERSION COMPLETED SUCCESSFULLY', [
                'attachment_id' => $attachment_id,
                'output_file' => basename($webp_path),
                'compression_ratio' => $webp_result['compression_ratio'] . '%'
            ]);
            $this->log_debug('========================================');

            wp_send_json_success([
                'message' => __('Image converted and replaced successfully!', 'mraf'),
                'attachment_id' => $attachment_id,
                'file_path' => $webp_path,
                'file_name' => basename($webp_path),
                'compression_ratio' => $webp_result['compression_ratio'],
                'log' => $conversion_log,
                'converted' => true
            ]);

        } catch (Exception $e) {
            $this->log_debug('✗ CONVERSION FAILED WITH EXCEPTION', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->log_debug('========================================');

            if ($temp_file !== $file_path && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            wp_send_json_error([
                'message' => $e->getMessage(),
                'log' => $conversion_log
            ]);
        }
    }

    /**
     * Get upload error message
     */
    private function get_upload_error_message($error_code)
    {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('File is too large.', 'mraf');
            case UPLOAD_ERR_PARTIAL:
                return __('File was only partially uploaded.', 'mraf');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'mraf');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing temporary folder.', 'mraf');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk.', 'mraf');
            case UPLOAD_ERR_EXTENSION:
                return __('File upload stopped by extension.', 'mraf');
            default:
                return __('Unknown upload error.', 'mraf');
        }
    }

    /**
     * AJAX: Get debug log
     */
    public function ajax_get_debug_log()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        // Clear log if requested
        if (isset($_POST['clear']) && $_POST['clear']) {
            delete_transient(self::DEBUG_KEY);
            wp_send_json_success(['log' => __('Log cleared.', 'mraf')]);
            return;
        }

        // Get debug log from transient
        $debug_log = get_transient(self::DEBUG_KEY);
        $log = ($debug_log && is_array($debug_log)) ? implode("\n", $debug_log) : __('No debug logs yet.', 'mraf');

        wp_send_json_success(['log' => $log]);
    }

    /**
     * Log debug message
     */
    private function log_debug($message, $data = null)
    {
        $debug_log = get_transient(self::DEBUG_KEY);
        $debug_log = is_array($debug_log) ? $debug_log : [];

        // Format message with timestamp
        $formatted_message = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message;

        if ($data !== null) {
            $formatted_message .= ' | Data: ' . json_encode($data);
        }

        $debug_log[] = $formatted_message;

        // Keep only last 100 entries
        if (count($debug_log) > 100) {
            $debug_log = array_slice($debug_log, -100);
        }

        set_transient(self::DEBUG_KEY, $debug_log, HOUR_IN_SECONDS);
    }

    /**
     * Clear debug log
     */
    public function clear_debug_log()
    {
        delete_transient(self::DEBUG_KEY);
    }

    /**
     * AJAX handler for searching images
     */
    public function ajax_search_images()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mraf')]);
        }

        global $wpdb;

        $query = sanitize_text_field($_POST['query'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');
        $unconverted_only = absint($_POST['unconverted_only'] ?? 0);
        $page = absint($_POST['page'] ?? 1);
        $per_page = 50;
        $where_cb = null;

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_mime_type' => 'image',
        ];

        // Search by title and alt text
        if ($query) {
            $where_cb = function($where) use ($query, $wpdb) {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $where .= $wpdb->prepare(
                    " AND (
                        {$wpdb->posts}.post_title LIKE %s
                        OR {$wpdb->posts}.post_content LIKE %s
                        OR {$wpdb->posts}.post_excerpt LIKE %s
                        OR {$wpdb->posts}.guid LIKE %s
                        OR EXISTS (
                            SELECT 1 FROM {$wpdb->postmeta} pm
                            WHERE pm.post_id = {$wpdb->posts}.ID
                            AND pm.meta_key IN ('_wp_attachment_image_alt', '_wp_attached_file')
                            AND pm.meta_value LIKE %s
                        )
                    )",
                    $like, $like, $like, $like, $like
                );
                return $where;
            };

            add_filter('posts_where', $where_cb, 10, 1);
        }

        // Filter by MIME type
        if ($type) {
            $args['post_mime_type'] = $type;
        }

        // Filter by date
        if ($date) {
            $args['date_query'] = $this->get_date_query($date);
        }

        // Filter for unconverted images only
        if ($unconverted_only) {
            $args['meta_query'] = [
                [
                    'key' => '_mraf_converted',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        }

        $query_obj = new WP_Query($args);

        if ($where_cb) {
            remove_filter('posts_where', $where_cb, 10);
        }

        $attachments = $query_obj->posts;
        $total = $query_obj->found_posts;
        $results = [];

        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            $thumb = wp_get_attachment_image_src($attachment->ID, 'thumbnail');
            $meta = wp_get_attachment_metadata($attachment->ID);
            $file_path = get_post_meta($attachment->ID, '_wp_attached_file', true);
            $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            $converted = (bool) get_post_meta($attachment->ID, '_mraf_converted', true);

            $results[] = [
                'id' => $attachment->ID,
                'title' => $attachment->post_title,
                'alt_text' => $alt_text,
                'url' => $url,
                'thumb' => $thumb ? $thumb[0] : $url,
                'filename' => basename($url),
                'filepath' => $file_path,
                'mime_type' => $attachment->post_mime_type,
                'width' => !empty($meta['width']) ? $meta['width'] : 0,
                'height' => !empty($meta['height']) ? $meta['height'] : 0,
                'dimensions' => !empty($meta['width']) ? $meta['width'] . ' × ' . $meta['height'] : 'Unknown',
                'filesize' => !empty($meta['filesize']) ? size_format($meta['filesize']) : 'Unknown',
                'converted' => $converted
            ];
        }

        wp_send_json_success([
            'images' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < $total
        ]);
    }

    /**
     * Create a backup of the current attachment files and metadata
     */
    private function create_backup($attachment_id)
    {
        $backup_dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '/' . $attachment_id;

        if (!wp_mkdir_p($backup_dir)) {
            $this->log_debug('Could not create backup directory: ' . $backup_dir);
            throw new Exception(__('Could not create backup directory.', 'mraf'));
        }

        // Basic retention: purge files older than 14 days in this attachment's backup dir
        foreach (glob($backup_dir . '/*') as $path) {
            if (is_file($path)) {
                $age = time() - filemtime($path);
                if ($age > 14 * DAY_IN_SECONDS) {
                    @unlink($path);
                }
            }
        }

        $old_file = get_attached_file($attachment_id);
        $old_meta = wp_get_attachment_metadata($attachment_id);
        $post = get_post($attachment_id);
        $backup_count = 0;

        if ($old_file && file_exists($old_file)) {
            if (@copy($old_file, $backup_dir . '/' . basename($old_file))) {
                $backup_count++;
            }
        }

        if (!empty($old_meta['sizes'])) {
            $dir = dirname($old_file);
            foreach ($old_meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb_path = trailingslashit($dir) . $size['file'];
                    if (file_exists($thumb_path)) {
                        if (@copy($thumb_path, $backup_dir . '/' . $size['file'])) {
                            $backup_count++;
                        }
                    }
                }
            }
        }

        $backup_data = [
            'post' => $post,
            'metadata' => $old_meta,
            'attached_file' => get_post_meta($attachment_id, '_wp_attached_file', true),
            'timestamp' => time(),
            'user_id' => get_current_user_id()
        ];

        file_put_contents($backup_dir . '/backup.json', json_encode($backup_data, JSON_PRETTY_PRINT));
        $this->log_debug('Backup created with ' . $backup_count . ' file(s) at ' . $backup_dir);
    }

    /**
     * Update stored URL references after a file name/extension change
     */
    private function update_url_references($old_url, $new_url)
    {
        global $wpdb;

        $stats = ['posts' => 0, 'meta' => 0, 'elementor' => 0, 'builders' => 0, 'options' => 0, 'widgets' => 0];

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['posts'] = $result !== false ? $result : 0;

        if (class_exists('MRAF_Image_Replacer')) {
            $stats['elementor'] = MRAF_Image_Replacer::update_elementor_urls($old_url, $new_url);
            $stats['builders'] = MRAF_Image_Replacer::update_page_builder_urls($old_url, $new_url);
        }

        $excluded_meta_keys = ['_elementor_data', '_elementor_css', '_elementor_page_settings', '_elementor_controls_usage', '_elementor_version', 'elementor_data', '_wpb_shortcodes_custom_css', '_fl_builder_data', '_fusion', '_et_pb_'];
        $exclude_conditions = [];
        foreach ($excluded_meta_keys as $key) {
            if (strpos($key, '%') !== false || strpos($key, '_et_pb_') !== false) {
                $exclude_conditions[] = $wpdb->prepare("meta_key NOT LIKE %s", $key . '%');
            } else {
                $exclude_conditions[] = $wpdb->prepare("meta_key != %s", $key);
            }
        }
        $exclude_sql = implode(' AND ', $exclude_conditions);

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
            WHERE meta_value LIKE %s AND {$exclude_sql}
            AND meta_value NOT LIKE 'a:%' AND meta_value NOT LIKE 'O:%' AND meta_value NOT LIKE '{%'",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));
        $stats['meta'] = $result !== false ? $result : 0;

        $search = [$old_url];
        $replace = [$new_url];
        $like = '%' . $wpdb->esc_like($old_url) . '%';

        $serialized_metas = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s AND {$exclude_sql}
            AND (meta_value LIKE 'a:%' OR meta_value LIKE 'O:%' OR meta_value LIKE '{%')",
            $like
        ));

        foreach ($serialized_metas as $m) {
            $raw = (string) $m->meta_value;
            $changed = false;
            $replaced_count = 0;

            if (class_exists('MRAF_Image_Replacer')) {
                $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);
            } else {
                $new = str_replace($old_url, $new_url, $raw);
                $changed = ($new !== $raw);
            }

            if ($changed) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $m->meta_id]);
                if (isset($m->post_id)) {
                    clean_post_cache((int) $m->post_id);
                }
                $stats['meta']++;
                $this->log_debug('Updated serialized meta: ' . $m->meta_key . ' (post_id: ' . $m->post_id . ', ' . $replaced_count . ' replacement(s))');
            }
        }

        $safe_option_prefixes = ['widget_', 'sidebars_widgets', '_transient_', 'acf_', 'options_', 'theme_mods_', 'gowild_'];
        $safe_option_names = ['upload_path', 'upload_url_path'];

        $opts = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
            WHERE option_value LIKE %s",
            $like
        ));

        foreach ($opts as $o) {
            $option_name = $o->option_name;

            $is_safe = in_array($option_name, $safe_option_names);
            if (!$is_safe) {
                foreach ($safe_option_prefixes as $prefix) {
                    if (strpos($option_name, $prefix) === 0) {
                        $is_safe = true;
                        break;
                    }
                }
            }

            $excluded_options = ['custom_css_', 'nav_menu_', 'category_children', 'rewrite_rules', 'cron'];
            $is_excluded = false;
            foreach ($excluded_options as $excluded) {
                if (strpos($option_name, $excluded) === 0 || $option_name === $excluded) {
                    $is_excluded = true;
                    break;
                }
            }

            if (!$is_safe || $is_excluded) {
                continue;
            }

            $raw = (string) $o->option_value;
            $changed = false;
            $replaced_count = 0;

            if (class_exists('MRAF_Image_Replacer')) {
                $new = MRAF_Image_Replacer::replace_maybe_serialized($raw, $search, $replace, $changed, $replaced_count);
            } else {
                $new = str_replace($old_url, $new_url, $raw);
                $changed = ($new !== $raw);
            }

            if ($changed) {
                $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $o->option_id]);
                if (!empty($o->option_name)) {
                    wp_cache_delete($o->option_name, 'options');
                }

                if (strpos($option_name, 'widget_') === 0) {
                    $stats['widgets']++;
                } else {
                    $stats['options']++;
                }

                $this->log_debug('Updated option: ' . $option_name . ' (' . $replaced_count . ' replacement(s))');
            }
        }

        if (class_exists('MRAF_Image_Replacer')) {
            MRAF_Image_Replacer::flush_builder_caches(0);
        }

        return $stats;
    }

    /**
     * Delete original attachment file and generated sizes
     */
    private function delete_attachment_files($file, $meta)
    {
        $result = ['success' => true, 'message' => '', 'deleted' => 0];

        if ($file && file_exists($file)) {
            if (@unlink($file)) {
                $result['deleted']++;
            } else {
                $result['success'] = false;
                $result['message'] = __('Failed to delete original file.', 'mraf');
                return $result;
            }
        }

        if (!empty($meta['sizes'])) {
            $dir = dirname($file);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $thumb = trailingslashit($dir) . $size['file'];
                    if (file_exists($thumb)) {
                        if (@unlink($thumb)) {
                            $result['deleted']++;
                        }
                    }
                }
            }
        }

        if (empty($result['message'])) {
            $result['message'] = sprintf(__('Deleted %d old file(s).', 'mraf'), $result['deleted']);
        }

        return $result;
    }

    /**
     * Get date query for filtering
     */
    private function get_date_query($date)
    {
        switch ($date) {
            case 'today': return ['after' => '1 day ago'];
            case 'week': return ['after' => '1 week ago'];
            case 'month': return ['after' => '1 month ago'];
            case 'year': return ['after' => '1 year ago'];
            default: return [];
        }
    }
}
