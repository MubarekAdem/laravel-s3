@extends('layouts.docs')
@section('title', 'API documentation · Harbor Storage')
@section('content')
    <article class="docs-main">
        <section id="overview">
            <span class="docs-version">Storage API reference</span>
            <h1>API documentation</h1>
            <p class="docs-intro">Use signed HTTP requests to store files on this server. The API uses S3-style paths, Signature V4 authentication, raw upload bodies, and XML control responses.</p>
            <div class="docs-notice"><strong>API base URL</strong><br><code>{{ rtrim(config('app.url'), '/') }}/s3</code><br>Region: <code>{{ config('object-store.region') }}</code>. Use path-style addressing: <code>/s3/{bucket}/{key}</code>.</div>
            <p>This is the reference for Harbor's implemented S3 subset. It is not a JSON REST API and does not require an AWS account. Browser session cookies and bearer tokens do not authenticate storage requests.</p>
        </section>

        <section id="quickstart">
            <h2>Your first upload</h2>
            <ol class="docs-steps">
                <li><a href="{{ route('dashboard') }}">Open the owner console</a>, create a bucket named <code>media</code>, and create an API key with <code>read</code>, <code>write</code>, and <code>list</code> permissions.</li>
                <li>Copy the secret when it is displayed. The console shows it only once. Keep it on a trusted server; never embed it in browser JavaScript.</li>
                <li>Use a curl build with <a href="https://curl.se/docs/manpage.html#--aws-sigv4" rel="noreferrer">Signature V4 support</a>. These examples use a POSIX shell; on Windows, use WSL or adapt them for <code>curl.exe</code>.</li>
            </ol>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>export ENDPOINT="{{ rtrim(config('app.url'), '/') }}/s3"
export REGION="{{ config('object-store.region') }}"
export ACCESS_KEY="YOUR_ACCESS_KEY"
export SECRET_KEY="YOUR_SECRET_KEY"

# Reusable helper: signs every request locally.
s3() {
  curl --fail-with-body --silent --show-error \
    --aws-sigv4 "aws:amz:${REGION}:s3" \
    --user "${ACCESS_KEY}:${SECRET_KEY}" "$@"
}

printf 'Hello from Harbor\n' &gt; hello.txt
s3 --request PUT --data-binary @hello.txt \
  --header 'Content-Type: text/plain' \
  "$ENDPOINT/media/hello.txt"

s3 "$ENDPOINT/media/hello.txt" --output downloaded.txt
s3 "$ENDPOINT/media?list-type=2&amp;prefix=hello&amp;max-keys=50"</code></pre></div>
            <p>The remaining curl examples use this <code>s3</code> helper. All commands target the configured Harbor endpoint; they do not contact AWS. Upload and copy return an <code>ETag</code> response header.</p>
        </section>

        <section id="authentication">
            <h2>Authentication &amp; permissions</h2>
            <p>Sign requests using <code>AWS4-HMAC-SHA256</code>, service <code>s3</code>, and the region above. A client library can generate the signature. Keep the endpoint's <code>/s3</code> prefix in the canonical URI.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>Authorization: AWS4-HMAC-SHA256 Credential=ACCESS_KEY/YYYYMMDD/REGION/s3/aws4_request, SignedHeaders=host;x-amz-date, Signature=64_HEX_CHARACTERS
x-amz-date: YYYYMMDDTHHMMSSZ
Host: your-storage-host</code></pre></div>
            <p>This is the header shape, not a usable signature. Signed header names must be lowercase, unique, and sorted. Include <code>host</code> (including a non-default port), and sign every supplied <code>x-amz-*</code> header except <code>x-amz-content-sha256</code>, which is represented in the canonical payload hash. Header-authenticated requests must be within 15 minutes of the server clock. Synchronize the client clock.</p>
            <p>The payload can use its SHA-256 digest or <code>UNSIGNED-PAYLOAD</code>. If a digest is supplied, Harbor verifies the received body against it. URL-encode key segments and query values, preserve slash separators in object keys, and include bare subresources such as <code>uploads=</code> when signing.</p>
            <div class="docs-table"><table><thead><tr><th>Scope</th><th>Allows</th></tr></thead><tbody>
                <tr><td><code>read</code></td><td>Object GET/HEAD, bucket HEAD/location, and reading a copy source.</td></tr>
                <tr><td><code>list</code></td><td>Listing buckets and objects.</td></tr>
                <tr><td><code>write</code></td><td>Creating buckets, uploading/copying objects, and starting/uploading/completing multipart uploads.</td></tr>
                <tr><td><code>delete</code></td><td>Deleting objects/buckets, bulk deletion, and aborting multipart uploads.</td></tr>
            </tbody></table></div>
            <p>A key can be restricted to selected bucket names. An empty bucket restriction means all buckets. Legacy CLI keys may use <code>*</code> for all actions; bucket restrictions still apply. Revoke keys in the owner console to disable them.</p>
        </section>

        <section id="buckets">
            <h2>Bucket operations</h2>
            <div class="docs-table"><table><thead><tr><th>Method</th><th>Path after /s3</th><th>Scope</th><th>Success</th></tr></thead><tbody>
                <tr><td><span class="docs-method">GET</span></td><td><code>/</code></td><td>list</td><td>200 · ListAllMyBucketsResult XML</td></tr>
                <tr><td><span class="docs-method">PUT</span></td><td><code>/{bucket}</code></td><td>write</td><td>200 · empty body</td></tr>
                <tr><td><span class="docs-method">HEAD</span></td><td><code>/{bucket}</code></td><td>read</td><td>200 · empty body</td></tr>
                <tr><td><span class="docs-method">GET</span></td><td><code>/{bucket}?location</code></td><td>read</td><td>200 · LocationConstraint XML</td></tr>
                <tr><td><span class="docs-method">GET</span></td><td><code>/{bucket}?list-type=2</code></td><td>list</td><td>200 · ListBucketResult XML</td></tr>
                <tr><td><span class="docs-method">DELETE</span></td><td><code>/{bucket}</code></td><td>delete</td><td>204 · bucket must be empty</td></tr>
            </tbody></table></div>
            <p>Create lowercase names of 3–63 characters using letters, digits, dots, and hyphens. Creating a bucket through this API does not set a quota; manage quotas in the owner console. Buckets containing active objects, retained trash, or unfinished uploads cannot be deleted.</p>
            <h3>Listing &amp; pagination</h3>
            <p>Use <code>prefix</code> to narrow keys and <code>max-keys</code> to request 1–1,000 results. Listings exclude trash and sort by key. When <code>IsTruncated</code> is true, pass the returned <code>NextContinuationToken</code> as <code>continuation-token</code> on the next request, preserving the same prefix.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>s3 "$ENDPOINT/"
s3 --request PUT "$ENDPOINT/media"
s3 --head "$ENDPOINT/media"
s3 "$ENDPOINT/media?location"
s3 "$ENDPOINT/media?list-type=2&amp;prefix=images%2F&amp;max-keys=100"
# Treat continuation tokens as opaque and URL-encode them.
s3 "$ENDPOINT/media?prefix=images%2F&amp;continuation-token=TOKEN"</code></pre></div>
            <p>The default region <code>us-east-1</code> has an empty LocationConstraint value. Other configured regions are returned as text.</p>
        </section>

        <section id="objects">
            <h2>Object operations</h2>
            <div class="docs-table"><table><thead><tr><th>Method</th><th>Path after /s3</th><th>Scope</th><th>Success</th></tr></thead><tbody>
                <tr><td><span class="docs-method">PUT</span></td><td><code>/{bucket}/{key}</code></td><td>write</td><td>200 · raw upload or copy</td></tr>
                <tr><td><span class="docs-method">GET</span></td><td><code>/{bucket}/{key}</code></td><td>read</td><td>200 · streamed bytes; 206 for a range</td></tr>
                <tr><td><span class="docs-method">HEAD</span></td><td><code>/{bucket}/{key}</code></td><td>read</td><td>200 · metadata headers</td></tr>
                <tr><td><span class="docs-method">DELETE</span></td><td><code>/{bucket}/{key}</code></td><td>delete</td><td>204 · moves an active object to trash</td></tr>
                <tr><td><span class="docs-method">POST</span></td><td><code>/{bucket}?delete</code></td><td>delete</td><td>200 · DeleteResult XML</td></tr>
            </tbody></table></div>
            <h3>Upload, metadata &amp; visibility</h3>
            <p>Send file bytes as the body, not multipart/form-data. <code>Content-Type</code> and <code>x-amz-meta-*</code> headers are saved on normal PUTs. Uploads are private unless <code>x-amz-acl: public-read</code> is supplied. That header makes active object GET/HEAD available without a signature; it does not make a bucket publicly listable.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>s3 --request PUT --data-binary @photo.jpg \
  --header 'Content-Type: image/jpeg' \
  --header 'x-amz-meta-project: website' \
  "$ENDPOINT/media/images/photo.jpg"

s3 --head "$ENDPOINT/media/images/photo.jpg"
s3 --header 'Range: bytes=0-1023' \
  "$ENDPOINT/media/images/photo.jpg" --output first-kilobyte.bin</code></pre></div>
            <h3>Conditional reads &amp; ranges</h3>
            <p>GET and HEAD return ETag, Last-Modified, Content-Type, and saved metadata headers. <code>If-None-Match</code> and <code>If-Modified-Since</code> can return 304. <code>If-Match</code> and <code>If-Unmodified-Since</code> can return 412. GET accepts one byte range, including suffix ranges, with <code>If-Range</code> support. A valid range returns Content-Range; an invalid range returns 416. Multiple ranges are not supported.</p>
            <h3>Copy an object</h3>
            <p>PUT the destination with an URL-encoded <code>x-amz-copy-source</code> path. The key needs write access to the destination and read access to the source, including both bucket restrictions. Copies inherit the source content type, metadata, and visibility. Success is an empty 200 response with an ETag header.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>s3 --request PUT \
  --header 'x-amz-copy-source: /media/images/photo.jpg' \
  "$ENDPOINT/media/images/photo-copy.jpg"</code></pre></div>
            <h3>Delete &amp; retention</h3>
            <p>DELETE is idempotent: absent or already-trashed objects return 204. Trashed objects disappear from S3 reads and lists. The owner can restore them while retained, or permanently purge them. This server's configured trash retention is <strong>{{ (int) config('object-store.trash_days') }} days</strong>; permanent cleanup depends on the scheduler.</p>
            <p>Bulk deletion accepts 1–1,000 keys in a <code>Delete</code> XML document. Include <code>&lt;Quiet&gt;true&lt;/Quiet&gt;</code> to omit successful Deleted entries. Missing keys are reported as deleted too.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>s3 --request POST --header 'Content-Type: application/xml' \
  --data-binary '&lt;Delete&gt;&lt;Object&gt;&lt;Key&gt;images/photo-copy.jpg&lt;/Key&gt;&lt;/Object&gt;&lt;/Delete&gt;' \
  "$ENDPOINT/media?delete"</code></pre></div>
        </section>

        <section id="multipart">
            <h2>Multipart uploads</h2>
            <p>Start an upload, send numbered parts, then submit the selected part numbers and their exact ETags. Complete requests must list parts in ascending order. Every selected part except the last must be at least 5 MiB.</p>
            <div class="docs-table"><table><thead><tr><th>Operation</th><th>Request</th><th>Scope</th></tr></thead><tbody>
                <tr><td>Initiate</td><td><code>POST /{bucket}/{key}?uploads</code> → UploadId XML</td><td>write</td></tr>
                <tr><td>Upload part</td><td><code>PUT /{bucket}/{key}?uploadId=ID&amp;partNumber=N</code> → ETag header</td><td>write</td></tr>
                <tr><td>Complete</td><td><code>POST /{bucket}/{key}?uploadId=ID</code> + completion XML</td><td>write</td></tr>
                <tr><td>Abort</td><td><code>DELETE /{bucket}/{key}?uploadId=ID</code> → 204</td><td>delete</td></tr>
            </tbody></table></div>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code># Read UploadId from the returned XML.
s3 --request POST --header 'Content-Type: application/octet-stream' \
  "$ENDPOINT/media/archive.bin?uploads"
export UPLOAD_ID="UPLOAD_ID_FROM_RESPONSE"

# Use --include to read the part ETag. Part numbers are 1–10,000.
s3 --include --request PUT --data-binary @part-1.bin \
  "$ENDPOINT/media/archive.bin?uploadId=$UPLOAD_ID&amp;partNumber=1"

# Replace the example ETag with the exact value from the part response.
s3 --request POST --header 'Content-Type: application/xml' \
  --data-binary '&lt;CompleteMultipartUpload&gt;&lt;Part&gt;&lt;PartNumber&gt;1&lt;/PartNumber&gt;&lt;ETag&gt;"PART_ETAG"&lt;/ETag&gt;&lt;/Part&gt;&lt;/CompleteMultipartUpload&gt;' \
  "$ENDPOINT/media/archive.bin?uploadId=$UPLOAD_ID"</code></pre></div>
            <p>A single final part may be smaller than 5 MiB. Re-uploading the same part number replaces that part. Completion may select a subset of uploaded parts; unselected parts are discarded on success. Multipart object ETags include a part-count suffix and are not the MD5 of the entire object.</p>
            <p>The configured per-object size limit applies to the assembled result as well as individual part uploads. Bucket quotas are checked on completion. Stale uploads expire after <strong>{{ (int) config('object-store.multipart_hours') }} hours</strong> without a part upload, when scheduled cleanup runs. Initiation preserves Content-Type and public-read visibility; custom multipart metadata, ListParts, and ListMultipartUploads are not implemented.</p>
        </section>

        <section id="presigned">
            <h2>Presigned URLs</h2>
            <p>A trusted backend can generate a Signature V4 query-authenticated URL for a client. It carries the signing key's action and bucket restrictions and stops working if the key is revoked. Treat the URL as a temporary credential.</p>
            <div class="docs-table"><table><thead><tr><th>Query parameter</th><th>Value</th></tr></thead><tbody>
                <tr><td><code>X-Amz-Algorithm</code></td><td>AWS4-HMAC-SHA256</td></tr>
                <tr><td><code>X-Amz-Credential</code></td><td>URL-encoded ACCESS_KEY/YYYYMMDD/REGION/s3/aws4_request</td></tr>
                <tr><td><code>X-Amz-Date</code></td><td>UTC date in YYYYMMDDTHHMMSSZ format</td></tr>
                <tr><td><code>X-Amz-Expires</code></td><td>1–604,800 seconds (up to 7 days)</td></tr>
                <tr><td><code>X-Amz-SignedHeaders</code></td><td>Sorted lowercase headers, including host</td></tr>
                <tr><td><code>X-Amz-Signature</code></td><td>Computed hexadecimal HMAC signature</td></tr>
            </tbody></table></div>
            <p>For the usual presigned download, use <code>UNSIGNED-PAYLOAD</code> in the canonical request. Generate the URL with your S3 signing library using this server's endpoint and path-style addressing. A client must send any additional signed headers exactly as signed. Never share the secret itself.</p>
        </section>

        <section id="errors">
            <h2>Errors &amp; retries</h2>
            <p>Failures use an XML Error response with Code, Message, Resource, and RequestId. The <code>x-amz-request-id</code> response header helps correlate a request with server activity. HEAD responses have no response body.</p>
            <div class="docs-code"><button type="button" data-copy>Copy</button><pre><code>&lt;Error xmlns="http://s3.amazonaws.com/doc/2006-03-01/"&gt;
  &lt;Code&gt;NoSuchKey&lt;/Code&gt;
  &lt;Message&gt;The requested resource does not exist.&lt;/Message&gt;
  &lt;Resource&gt;s3/media/missing.txt&lt;/Resource&gt;
  &lt;RequestId&gt;SERVER_GENERATED_ID&lt;/RequestId&gt;
&lt;/Error&gt;</code></pre></div>
            <div class="docs-table"><table><thead><tr><th>Status</th><th>Code</th><th>What to check</th></tr></thead><tbody>
                <tr><td>400</td><td>InvalidRequest / EntityTooSmall</td><td>XML shape, part order/ETags, part size, and query parameters.</td></tr>
                <tr><td>403</td><td>AccessDenied</td><td>Signature, time window, key revocation, action scope, and bucket restriction. The message gives more detail.</td></tr>
                <tr><td>404</td><td>NoSuchBucket / NoSuchKey / NoSuchUpload</td><td>The named resource is absent, trashed, or already completed/aborted.</td></tr>
                <tr><td>409</td><td>BucketNotEmpty</td><td>Remove active objects, retained trash, and unfinished uploads before deleting the bucket.</td></tr>
                <tr><td>412</td><td>PreconditionFailed</td><td>The object's ETag or modification time did not meet a read precondition.</td></tr>
                <tr><td>413</td><td>EntityTooLarge</td><td>The configured upload limit or bucket quota was exceeded.</td></tr>
                <tr><td>416</td><td>InvalidRange</td><td>Request one satisfiable byte range.</td></tr>
                <tr><td>500 / 503</td><td>InternalError / ServiceUnavailable</td><td>Retry transient failures with bounded backoff. Persistent recovery failures need the server owner.</td></tr>
            </tbody></table></div>
            <p>Fix 4xx errors before retrying. For uncertain upload results, use HEAD to inspect the object before retrying. After a successful multipart completion the UploadId no longer exists, so blindly retrying the same completion can return NoSuchUpload.</p>
        </section>

        <section id="ai-reference">
            <h2>AI &amp; machine-readable reference</h2>
            <p>Give an AI coding assistant the reference URLs below to build against this server's supported API. The full text is generated from this page, including its configured limits.</p>
            <div class="docs-resource-grid">
                <a href="{{ route('ai.index') }}"><x-console-icon name="file" /><strong>llms.txt</strong><span>Discovery index and reference links</span></a>
                <a href="{{ route('ai.full') }}"><x-console-icon name="book" /><strong>llms-full.txt</strong><span>Complete reference as plain text</span></a>
                <a href="{{ route('api.openapi') }}"><x-console-icon name="download" /><strong>OpenAPI 3.1</strong><span>HTTP paths, parameters, and schemas</span></a>
            </div>
            <p>The OpenAPI file describes shared S3 subresource operations in each method's description. Signing requires a Signature V4-capable client; entering an access key as a bearer token will not work.</p>
        </section>

        <section id="limits">
            <h2>Limits &amp; compatibility</h2>
            <div class="docs-table"><table><thead><tr><th>Setting</th><th>This server</th></tr></thead><tbody>
                <tr><td>Maximum object or part body</td><td>{{ number_format((int) config('object-store.max_bytes')) }} bytes · server configured</td></tr>
                <tr><td>Multipart part numbers</td><td>1–10,000; non-final parts ≥ 5 MiB</td></tr>
                <tr><td>Object listing page</td><td>1–1,000 keys</td></tr>
                <tr><td>Bulk deletion</td><td>1–1,000 keys per request</td></tr>
                <tr><td>Presigned URL expiry</td><td>1 second–7 days</td></tr>
                <tr><td>Addressing</td><td>Path-style only; include the /s3 base path</td></tr>
            </tbody></table></div>
            <div class="docs-notice"><strong>Configure clients for the supported subset.</strong> Prefer ordinary signed PUTs or the multipart operations above. Disable automatic features your SDK may add that this server does not implement.</div>
            <p>Not implemented: virtual-hosted buckets, IAM/bucket policies, object versioning, AWS STS/session tokens, server-side encryption APIs, object tagging/locking, lifecycle APIs, multipart-copy, streaming chunk signatures, additional checksum APIs, delimiter/CommonPrefixes listing, browser form POST uploads, and configurable cross-origin browser uploads. Only the public-read upload flag is supported from the S3 ACL vocabulary.</p>
            <p>The owner console uses separate authenticated browser routes and CSRF-protected forms. This page documents the storage API, not a public administrative API. Actual capacity and throughput depend on the host's disk, database, PHP limits, and configuration.</p>
        </section>
    </article>
@endsection
