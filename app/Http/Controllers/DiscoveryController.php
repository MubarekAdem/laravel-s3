<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class DiscoveryController extends Controller
{
    public function robots(): Response
    {
        $rules = "User-agent: *\n";
        if (! config('seo.indexable')) {
            $rules .= "Disallow: /\n";
        } else {
            $rules .= "Allow: /\n";
            foreach (['dashboard', 'profile', 'login', 'logout', 'buckets', 'keys', 's3', 'up'] as $path) {
                $rules .= 'Disallow: /'.$path."\n";
            }
            $rules .= "\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n";
        }

        return response($rules, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = config('seo.indexable') ? [rtrim(config('app.url'), '/').'/', rtrim(config('app.url'), '/').'/api/docs'] : [];

        return response()->view('discovery.sitemap', compact('urls'), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
