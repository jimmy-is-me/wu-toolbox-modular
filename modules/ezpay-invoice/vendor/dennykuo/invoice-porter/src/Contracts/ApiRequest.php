<?php

namespace WUTMInvoiceDeps\InvoicePorter\Contracts;

interface ApiRequest
{
    public function send(string $apiUri, array $postData);
}