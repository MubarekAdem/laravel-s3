<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ApiDocumentationController extends Controller
{
    public function index(): Response
    {
        $text = "# Laravel-S3\n\n> Self-hosted Laravel object storage with a documented S3-compatible subset.\n\n";
        $text .= "Files are stored on this Laravel-S3 server's disk. Storage requests go directly to Laravel-S3 and are never forwarded to Amazon S3. Use Laravel-S3-issued credentials, not AWS credentials. S3 compatibility describes the request format only.\n\nUse path-style addressing and Signature V4; browser cookies and bearer tokens do not authenticate storage requests. An AWS account is not required.\n\n## Reference\n\n";
        $text .= '- [API documentation]('.route('api.docs')."): Human-readable setup, authentication, operations, examples, errors, and limits.\n";
        $text .= '- [Full reference]('.route('ai.full')."): Plain-text version of the same reference for AI clients.\n";
        $text .= '- [OpenAPI 3.1]('.route('api.openapi')."): Machine-readable HTTP operations, parameters, request bodies, and responses.\n\n";
        $text .= 'Storage base URL: '.rtrim(config('app.url'), '/').'/s3'."\nSigning region (compatibility value only, not a hosting location): ".config('object-store.region')."\n\n";
        $text .= "## Compatibility\n\nThis is an S3 subset. Consult the full reference before selecting SDK features. API keys have read, write, list, and delete scopes and optional bucket restrictions. No public administration API is provided.\n";

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function full(): Response
    {
        $html = view('api.docs')->render();
        preg_match('/<article class="docs-main">(.*?)<\/article>/s', $html, $matches);
        $article = $matches[1] ?? '';
        $article = preg_replace('/<button\b[^>]*>.*?<\/button>/s', '', $article);
        $article = preg_replace('/<h([1-3])\b[^>]*>(.*?)<\/h\1>/s', "\n\n## $2\n\n", $article);
        $article = preg_replace('/<\/(?:p|div|section|pre|li|tr|ol|ul)>/i', "$0\n", $article);
        $article = preg_replace('/<\/(?:td|th)>/i', ' | ', $article);
        $text = html_entity_decode(strip_tags($article), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n[\t ]+/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return response('# Laravel-S3 API reference'."\n\nSource: ".route('api.docs')."\nOpenAPI: ".route('api.openapi')."\n\n".trim($text)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function openapi(): JsonResponse
    {
        $bucket = ['name' => 'bucket', 'in' => 'path', 'required' => true, 'description' => 'Lowercase bucket name, 3–63 characters.', 'schema' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 63]];
        $key = ['name' => 'key', 'in' => 'path', 'required' => true, 'description' => 'Object key. Encode path segments while preserving slash separators; nested keys are supported.', 'schema' => ['type' => 'string', 'minLength' => 1]];
        $paths = [
            '/' => ['get' => $this->operation('listBuckets', 'List accessible buckets', 'list', ['200' => $this->xml('ListAllMyBucketsResult XML')])],
            '/{bucket}' => [
                'parameters' => [$bucket],
                'put' => $this->operation('createBucket', 'Create a bucket', 'write', ['200' => ['description' => 'Bucket created; empty body.']]),
                'head' => $this->operation('headBucket', 'Check bucket existence and access', 'read', ['200' => ['description' => 'Bucket exists; empty body.']]),
                'get' => $this->operation('listObjectsOrGetLocation', 'List objects or retrieve bucket location', 'list (listing) / read (location)', ['200' => $this->xml('ListBucketResult XML, or LocationConstraint XML when location is present.')], [
                    $this->query('location', 'string', 'Presence selects GetBucketLocation; omit listing parameters.'),
                    $this->query('list-type', 'string', 'Use 2 for S3-style listing.'),
                    $this->query('prefix', 'string', 'Only return keys beginning with this prefix.'),
                    $this->query('max-keys', 'integer', 'Page size, 1–1000.', ['minimum' => 1, 'maximum' => 1000]),
                    $this->query('continuation-token', 'string', 'Opaque token from the previous response.'),
                ]),
                'delete' => $this->operation('deleteBucket', 'Delete an empty bucket', 'delete', ['204' => ['description' => 'Bucket deleted. Must contain no active objects, retained trash, or incomplete multipart uploads.']]),
                'post' => $this->operation('deleteObjects', 'Delete up to 1000 objects', 'delete', ['200' => $this->xml('DeleteResult XML')], [$this->query('delete', 'string', 'Required subresource; send ?delete or ?delete=.', [], true)]),
            ],
            '/{bucket}/{key}' => [
                'parameters' => [$bucket, $key],
                'get' => $this->operation('getObject', 'Download an object', 'read', [
                    '200' => ['description' => 'Object bytes.', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                    '206' => ['description' => 'A single byte range; Content-Range identifies returned bytes.'],
                    '304' => ['description' => 'Not modified; empty body.'],
                ], array_map(fn (string $name): array => ['name' => $name, 'in' => 'header', 'schema' => ['type' => 'string']], ['Range', 'If-Range', 'If-Match', 'If-None-Match', 'If-Modified-Since', 'If-Unmodified-Since'])),
                'head' => $this->operation('headObject', 'Read object metadata', 'read', ['200' => ['description' => 'Object headers including ETag and Content-Length; no response body.'], '304' => ['description' => 'Not modified; empty body.']]),
                'put' => $this->operation('putObjectOrUploadPart', 'Upload, copy, or upload a multipart part', 'write (plus read on copy source)', ['200' => ['description' => 'Empty body; ETag response header identifies uploaded content. Copy also returns an empty body.']], [
                    $this->query('uploadId', 'string', 'For UploadPart: required together with partNumber.'),
                    $this->query('partNumber', 'integer', 'For UploadPart: 1–10000.', ['minimum' => 1, 'maximum' => 10000]),
                    ['name' => 'x-amz-copy-source', 'in' => 'header', 'description' => 'Copy source /bucket/key, URL-encoded. Omit uploadId and partNumber for copies.', 'schema' => ['type' => 'string']],
                    ['name' => 'x-amz-acl', 'in' => 'header', 'description' => 'Optional public-read flag. Other ACL vocabulary is unsupported.', 'schema' => ['type' => 'string', 'enum' => ['public-read']]],
                ]),
                'post' => $this->operation('initiateOrCompleteMultipart', 'Initiate or complete multipart upload', 'write', ['200' => $this->xml('InitiateMultipartUploadResult XML for ?uploads, or CompleteMultipartUploadResult XML for ?uploadId=ID.')], [
                    $this->query('uploads', 'string', 'Presence initiates multipart upload. Mutually exclusive with uploadId.'),
                    $this->query('uploadId', 'string', 'Complete the upload using submitted PartNumber/ETag pairs in XML. Mutually exclusive with uploads.'),
                ]),
                'delete' => $this->operation('deleteObjectOrAbortMultipart', 'Trash an object or abort multipart upload', 'delete', ['204' => ['description' => 'Object soft-deleted (idempotent), or multipart upload aborted if uploadId was supplied.']], [$this->query('uploadId', 'string', 'Presence selects AbortMultipartUpload.')]),
            ],
        ];
        $paths['/{bucket}']['post']['requestBody'] = ['required' => true, 'content' => ['application/xml' => ['schema' => ['type' => 'string'], 'example' => '<Delete><Object><Key>hello.txt</Key></Object><Quiet>false</Quiet></Delete>']]];
        $paths['/{bucket}/{key}']['put']['requestBody'] = ['description' => 'Raw bytes for normal uploads and parts. For copy, provide x-amz-copy-source instead.', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
        $paths['/{bucket}/{key}']['post']['requestBody'] = ['description' => 'Only completion requires XML. Part numbers must increase and ETags must exactly match uploaded parts. Non-final parts must be at least 5 MiB.', 'content' => ['application/xml' => ['schema' => ['type' => 'string'], 'example' => '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"PART_ETAG"</ETag></Part></CompleteMultipartUpload>']]];

        return response()->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Laravel-S3 S3 subset', 'version' => '1.0.0', 'description' => 'Self-hosted storage: files live on the Laravel-S3 server disk, and storage requests are not forwarded to Amazon S3. Use Laravel-S3-issued credentials. S3 compatibility describes the request format only; signing region is a local compatibility value, not a hosting location. Query subresources share HTTP method/path pairs; descriptions explain dispatch. Disable unsupported SDK features. Sign the complete /s3-prefixed canonical URI. Read the linked reference for supported operations and limits.'],
            'servers' => [['url' => rtrim(config('app.url'), '/').'/s3']],
            'externalDocs' => ['description' => 'Full API reference', 'url' => route('api.docs')],
            'security' => [['SignatureV4' => []], ['PresignedSignature' => []]],
            'paths' => $paths,
            'components' => ['securitySchemes' => [
                'SignatureV4' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'Authorization', 'description' => 'AWS4-HMAC-SHA256 signature, service s3, region '.config('object-store.region').'. Requires x-amz-date and a canonical signed request; a static token is insufficient. Timestamp tolerance: 15 minutes. Sign all supplied x-amz-* headers except x-amz-content-sha256.'],
                'PresignedSignature' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'X-Amz-Signature', 'description' => 'Computed SigV4 query signature. Also requires X-Amz-Algorithm=AWS4-HMAC-SHA256, X-Amz-Credential, X-Amz-Date, X-Amz-Expires (1–604800), and X-Amz-SignedHeaders. Not a standalone token.'],
            ]],
            'x-harbor-limits' => ['region' => config('object-store.region'), 'maxBodyBytes' => (int) config('object-store.max_bytes'), 'maxListKeys' => 1000, 'maxDeleteKeys' => 1000, 'maxPartNumber' => 10000, 'minNonFinalPartBytes' => 5242880],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function operation(string $id, string $summary, string $scope, array $responses, array $parameters = []): array
    {
        return ['operationId' => $id, 'summary' => $summary, 'description' => 'Required key scope: '.$scope.'. Public-read objects also permit unsigned GET/HEAD.', 'parameters' => $parameters, 'responses' => $responses + ['default' => $this->xml('S3 Error XML with Code, Message, Resource, RequestId. HEAD errors have no body. Statuses include 400, 403, 404, 409, 412, 413, 416, 500, 503.')], 'x-harbor-scope' => $scope];
    }

    private function xml(string $description): array
    {
        return ['description' => $description, 'content' => ['application/xml' => ['schema' => ['type' => 'string']]]];
    }

    private function query(string $name, string $type, string $description, array $constraints = [], bool $required = false): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => $required, 'description' => $description, 'allowEmptyValue' => $type === 'string', 'schema' => ['type' => $type] + $constraints];
    }
}
