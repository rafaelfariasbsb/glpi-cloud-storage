<?php

/**
 * Azure Blob Storage - Document download endpoint
 *
 * Replaces the core /front/document.send.php for documents stored in Azure.
 * Replicates the same access control checks as the core endpoint.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Azureblobstorage\AzureBlobClient;
use GlpiPlugin\Azureblobstorage\Config;
use GlpiPlugin\Azureblobstorage\DocumentTracker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

include('../../../inc/includes.php');

if (!isset($_GET['docid'])) {
    $exception = new BadRequestHttpException();
    $exception->setMessageToDisplay(__('Missing document ID'));
    throw $exception;
}

$docId = (int) $_GET['docid'];
$doc = new Document();

if (!$doc->getFromDB($docId)) {
    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay(__('Unknown file'));
    throw $exception;
}

// Replicate core access control
if (!$doc->canViewFile($_GET)) {
    $exception = new AccessDeniedHttpException();
    $exception->setMessageToDisplay(__('Unauthorized access to this file'));
    throw $exception;
}

// Check if document is tracked in Azure
$tracking = DocumentTracker::getByDocumentId($docId);

if ($tracking === null) {
    // Not in Azure - serve from local if possible
    if (!empty($doc->fields['filepath'])) {
        $localPath = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (file_exists($localPath)) {
            $doc->getAsResponse()->send();
            exit;
        }
    }

    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay(sprintf(__('File %s not found.'), $doc->fields['filename']));
    throw $exception;
}

// Document is in Azure - serve it
$blobPath = $tracking['azure_blob_name'];
$downloadMethod = Config::getDownloadMethod();

try {
    if ($downloadMethod === 'sas_redirect') {
        // Generate SAS URL and redirect
        $client = AzureBlobClient::getInstance();
        $sasUrl = $client->generateSasUrl($blobPath, Config::getSasExpiryMinutes());

        (new RedirectResponse($sasUrl, 302))->send();
        exit;
    }

    // Proxy mode: stream content through GLPI (memory-safe for large files)
    $client = AzureBlobClient::getInstance();

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
        'Cache-Control'       => 'private, must-revalidate',
    ]);

    $response->send();
    exit;
} catch (\Throwable $e) {
    trigger_error(
        sprintf('[AzureBlobStorage] Download failed for document %d: %s', $docId, $e->getMessage()),
        E_USER_WARNING
    );

    // Try to serve from local as fallback
    $localPath = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
    if (file_exists($localPath)) {
        $doc->getAsResponse()->send();
        exit;
    }

    $exception = new NotFoundHttpException();
    $exception->setMessageToDisplay(__('File temporarily unavailable. Please try again later.'));
    throw $exception;
}
