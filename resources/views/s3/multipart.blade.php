<?xml version="1.0" encoding="UTF-8"?>
@if ($operation === 'initiate')
<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>{{ $bucketName }}</Bucket><Key>{{ $objectKey }}</Key><UploadId>{{ $uploadId }}</UploadId></InitiateMultipartUploadResult>
@else
<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>{{ $bucketName }}</Bucket><Key>{{ $objectKey }}</Key><ETag>"{{ $etag }}"</ETag></CompleteMultipartUploadResult>
@endif
