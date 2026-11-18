<?xml version="1.0" encoding="UTF-8"?>
<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
@if (! $quiet)
@foreach ($keys as $key)
<Deleted><Key>{{ $key }}</Key></Deleted>
@endforeach
@endif
</DeleteResult>
