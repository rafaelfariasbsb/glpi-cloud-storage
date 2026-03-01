<?php

/**
 * Cloud Storage - Document download endpoint
 *
 * Replaces the core /front/document.send.php for documents stored in cloud storage.
 * Replicates the same access control checks as the core endpoint.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Cloudstorage\Config;
use GlpiPlugin\Cloudstorage\DocumentTracker;
use GlpiPlugin\Cloudstorage\StorageClientFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

include('../../../inc/includes.php');

$doc = new Document();
$docId = null;

if (isset($_GET['docid'])) {
    // Standard document download by ID
    $docId = (int) $_GET['docid'];
    if (!$doc->getFromDB($docId)) {
        $exception = new NotFoundHttpException();
        $exception->setMessageToDisplay(__('Unknown file'));
        throw $exception;
    }
} elseif (isset($_GET['file'])) {
    // Inline image/file by filepath (used by rich text editor)
    $filepath = $_GET['file'];
    // Look up document by filepath
    $docs = $doc->find(['filepath' => $filepath], [], 1);
    if (empty($docs)) {
        $exception = new NotFoundHttpException();
        $exception->setMessageToDisplay(__('Unknown file'));
        throw $exception;
    }
    $docData = reset($docs);
    $docId = (int) $docData['id'];
    $doc->getFromDB($docId);
} else {
    $exception = new BadRequestHttpException();
    $exception->setMessageToDisplay(__('Missing document ID'));
    throw $exception;
}

// Replicate core access control
if (!$doc->canViewFile($_GET)) {
    $exception = new AccessDeniedHttpException();
    $exception->setMessageToDisplay(__('Unauthorized access to this file'));
    throw $exception;
}

// Check if document is tracked in cloud storage
$tracking = DocumentTracker::getByDocumentId($docId);

if ($tracking === null) {
    // Not in Azure - serve from local if possible
    if (!empty($doc->fields['filepath'])) {
        $localPath = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (file_exists($localPath)) {
            $doc->getAsResponse()->send();
            return;
        }
    }

    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay(htmlescape(sprintf(__('File %s not found.'), $doc->fields['filename'])));
    throw $exception;
}

// Document is in cloud storage - serve it
$blobPath = $tracking['remote_path'];
$downloadMethod = Config::getDownloadMethod();

try {
    if ($downloadMethod === 'redirect') {
        // Generate temporary URL and redirect
        $client = StorageClientFactory::getInstance();
        $sasUrl = $client->generateTemporaryUrl($blobPath, Config::getUrlExpiryMinutes());

        $response = new RedirectResponse($sasUrl, 302);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->send();
        return;
    }

    // Proxy mode: stream content through GLPI (memory-safe for large files)
    $client = StorageClientFactory::getInstance();

    $filename = $doc->fields['filename'] ?? basename($blobPath);
    $mime = $doc->fields['mime'] ?? 'application/octet-stream';

    // Sanitize filename for Content-Disposition header (RFC 6266)
    $safeFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename);
    $safeFilename = str_replace(['"', '\\'], '_', $safeFilename);

    // Determine if inline or attachment
    $disposition = 'attachment';
    if (
        str_starts_with($mime, 'image/')
        || $mime === 'application/pdf'
    ) {
        $disposition = 'inline';
    }

    $response = new StreamedResponse(function () use ($client, $blobPath) {
        $stream = $client->readStream($blobPath);
        $out = fopen('php://output', 'wb');
        stream_copy_to_stream($stream, $out);
        if (is_resource($stream)) {
            fclose($stream);
        }
        fclose($out);
    }, 200, [
        'Content-Type'        => $mime,
        'Content-Disposition' => sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $safeFilename,
            rawurlencode($filename)
        ),
        'Cache-Control'            => 'private, must-revalidate',
        'X-Content-Type-Options'   => 'nosniff',
        'X-Frame-Options'          => 'DENY',
        'Referrer-Policy'          => 'no-referrer',
    ]);

    $response->send();
    return;
} catch (\Throwable $e) {
    trigger_error(
        sprintf('[CloudStorage] Download failed for document %d: %s', $docId, $e->getMessage()),
        E_USER_WARNING
    );
    \Toolbox::logInFile('cloudstorage', sprintf(
        "DOWNLOAD FAILED | doc_id=%d | blob=%s | method=%s | error=%s\n%s\n",
        $docId,
        $blobPath,
        $downloadMethod,
        $e->getMessage(),
        $e->getTraceAsString()
    ));

    // Try to serve from local as fallback
    $localPath = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
    if (file_exists($localPath)) {
        $doc->getAsResponse()->send();
        return;
    }

    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay(__('File temporarily unavailable. Please try again later.'));
    throw $exception;
}
