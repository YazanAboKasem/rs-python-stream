<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class RecordingUploadController extends Controller
{
    /**
     * Base directory where recordings are stored on the VPS.
     * Structure: storage/app/recordings/{jetson_name}/{cam_id}/{date}/{filename}
     */
    private function getRecordingsBasePath(): string
    {
        return storage_path('app/recordings');
    }

    /**
     * POST /api/surveillance/recordings/upload
     *
     * Receives a recording file from a Jetson device via HTTP multipart upload.
     * The file is stored under: recordings/{jetson_name}/{relative_path}
     */
    public function upload(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'file' => 'required|file|max:512000', // max 500MB per file
            'jetson_name' => 'required|string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'relative_path' => 'required|string|max:500',
            'overwrite' => 'boolean',
        ]);

        $jetsonName = $request->input('jetson_name');
        $relativePath = $request->input('relative_path');
        $overwrite = $request->boolean('overwrite', false);

        // Sanitize relative_path to prevent directory traversal
        $relativePath = str_replace(['..', "\0"], '', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        $basePath = $this->getRecordingsBasePath();
        $fullPath = "{$basePath}/{$jetsonName}/{$relativePath}";
        $dir = dirname($fullPath);

        // Check if file exists and overwrite is not set
        if (File::exists($fullPath) && ! $overwrite) {
            return response()->json([
                'success' => true,
                'status' => 'skipped',
                'message' => 'File already exists on server.',
            ]);
        }

        // Create directory if it doesn't exist
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Move uploaded file to target location
        try {
            $request->file('file')->move($dir, basename($fullPath));

            Log::info("[RecordingUpload] Saved: {$jetsonName}/{$relativePath}");

            return response()->json([
                'success' => true,
                'status' => 'uploaded',
                'path' => "{$jetsonName}/{$relativePath}",
            ]);
        } catch (\Exception $e) {
            Log::error("[RecordingUpload] Failed: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error' => 'Failed to save file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/surveillance/recordings/browse/{jetsonName?}
     *
     * List recordings stored on VPS, optionally filtered by Jetson name.
     */
    public function browse(Request $request, ?string $jetsonName = null): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $basePath = $this->getRecordingsBasePath();

        // List all Jetsons if no specific one requested
        if (! $jetsonName) {
            $jetsons = [];
            if (File::isDirectory($basePath)) {
                foreach (File::directories($basePath) as $dir) {
                    $name = basename($dir);
                    $size = $this->getDirectorySize($dir);
                    $fileCount = $this->getFileCount($dir);
                    $jetsons[] = [
                        'name' => $name,
                        'total_files' => $fileCount,
                        'total_size_bytes' => $size,
                        'total_size_gb' => round($size / (1024 ** 3), 2),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'jetsons' => $jetsons,
            ]);
        }

        // Validate jetson name
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $jetsonName)) {
            return response()->json(['error' => 'Invalid Jetson name'], 400);
        }

        $jetsonPath = "{$basePath}/{$jetsonName}";
        if (! File::isDirectory($jetsonPath)) {
            return response()->json([
                'success' => true,
                'jetson_name' => $jetsonName,
                'recordings' => [],
            ]);
        }

        // Build file tree: cam -> date -> files
        $recordings = [];
        foreach (File::directories($jetsonPath) as $camDir) {
            $camId = basename($camDir);
            $dates = [];

            foreach (File::directories($camDir) as $dateDir) {
                $date = basename($dateDir);
                $files = [];

                foreach (File::files($dateDir) as $file) {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size_bytes' => $file->getSize(),
                        'size_mb' => round($file->getSize() / (1024 * 1024), 1),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                        'download_url' => "/api/surveillance/recordings/download/{$jetsonName}/{$camId}/{$date}/{$file->getFilename()}",
                    ];
                }

                if (! empty($files)) {
                    $dates[] = [
                        'date' => $date,
                        'files' => $files,
                    ];
                }
            }

            // Sort dates descending
            usort($dates, fn($a, $b) => strcmp($b['date'], $a['date']));

            $recordings[] = [
                'camera' => $camId,
                'dates' => $dates,
            ];
        }

        return response()->json([
            'success' => true,
            'jetson_name' => $jetsonName,
            'recordings' => $recordings,
        ]);
    }

    /**
     * GET /api/surveillance/recordings/download/{jetsonName}/{path}
     *
     * Download a specific recording file.
     */
    public function download(Request $request, string $jetsonName, string $path): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Validate jetson name
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $jetsonName)) {
            return response()->json(['error' => 'Invalid Jetson name'], 400);
        }

        // Sanitize path
        $path = str_replace(['..', "\0"], '', $path);
        $fullPath = $this->getRecordingsBasePath() . "/{$jetsonName}/{$path}";

        if (! File::exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->download($fullPath);
    }

    /**
     * Helper: recursive directory size
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    /**
     * Helper: recursive file count
     */
    private function getFileCount(string $path): int
    {
        return count(File::allFiles($path));
    }

    /**
     * Helper to validate token
     */
    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}
