<?xml version="1.0" encoding="UTF-8"?>
<Error xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Code>{{ $code }}</Code><Message>{{ $message }}</Message><Resource>{{ request()->path() }}</Resource><RequestId>{{ request()->attributes->get('s3_request_id') }}</RequestId></Error>
