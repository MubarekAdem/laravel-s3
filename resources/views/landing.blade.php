@extends('layouts.public')

@section('title', 'Harbor Storage — Object storage on your own shore')

@section('content')
    <section class="harbor-hero harbor-container" aria-labelledby="hero-title">
        <div class="harbor-hero-copy">
            <p class="harbor-kicker"><span aria-hidden="true"></span> Your server. Your storage.</p>
            <h1 id="hero-title">Give your files<br>a home port.</h1>
            <p class="harbor-lead">Practical object storage for the apps you build. Run Harbor on your own Laravel hosting and connect through a familiar S3 API.</p>
            <div class="harbor-actions">
                <a class="harbor-button" href="{{ route('api.docs') }}">Explore the API <span aria-hidden="true">↗</span></a>
                <a class="harbor-button harbor-button-secondary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Open owner console</a>
            </div>
            <p class="harbor-hero-note">Pure Laravel. Path-style requests. cPanel-friendly.</p>
        </div>

        <figure class="harbor-object-map">
            <div class="harbor-map-heading"><span>Inside your harbor</span><span class="harbor-map-label">Object structure</span></div>
            <svg class="harbor-map-svg" viewBox="0 0 460 325" role="img" aria-labelledby="object-map-title object-map-description">
                <title id="object-map-title">From your application to a bucket and its objects</title>
                <desc id="object-map-description">Your application connects to Harbor Storage. An example bucket called project-assets contains images, documents, and backups.</desc>
                <defs>
                    <pattern id="harbor-grid" width="24" height="24" patternUnits="userSpaceOnUse"><path d="M24 0H0V24" fill="none" stroke="#254057" stroke-width=".6"/></pattern>
                </defs>
                <rect width="460" height="325" fill="url(#harbor-grid)"/>
                <path d="M230 63v38m0 65v25H80v32m150-32v32m0-32h150v32" fill="none" stroke="#67e8f9" stroke-width="1.5"/>
                <rect x="144" y="19" width="172" height="44" rx="6" fill="#142c40" stroke="#486076"/>
                <text x="230" y="46" text-anchor="middle" fill="#f5f3ec" font-size="14">Your application</text>
                <path d="M152 113l78-24 78 24v49l-78 24-78-24z" fill="#2457e8"/>
                <path d="M152 113l78 24 78-24m-78 24v49" fill="none" stroke="#89a9ff" stroke-width="1.5"/>
                <text x="230" y="119" text-anchor="middle" fill="white" font-size="13">project-assets</text>
                <g fill="#10283c" stroke="#486076">
                    <rect x="22" y="223" width="116" height="68" rx="5"/>
                    <rect x="172" y="223" width="116" height="68" rx="5"/>
                    <rect x="322" y="223" width="116" height="68" rx="5"/>
                </g>
                <g stroke="#67e8f9" fill="none" stroke-width="1.5">
                    <rect x="70" y="236" width="20" height="15" rx="2"/><path d="m70 249 6-6 5 4 4-5 5 6"/>
                    <path d="M223 234h10l5 5v15h-15zM233 234v6h5m-11 5h7m-7 4h7"/>
                    <path d="M370 236h20v5h-20zm2 5v13h16v-13m-12 5h8"/>
                </g>
                <g text-anchor="middle" fill="#f5f3ec" font-size="12"><text x="80" y="276">images/</text><text x="230" y="276">documents/</text><text x="380" y="276">backups/</text></g>
            </svg>
            <figcaption><span class="harbor-method">GET</span><code>/s3/project-assets/images/cover.jpg</code><span class="harbor-example">Example path</span></figcaption>
        </figure>
    </section>

    <section class="harbor-paper" aria-labelledby="storage-title">
        <div class="harbor-container">
            <div class="harbor-section-heading">
                <h2 id="storage-title">Familiar building blocks.<br>A smaller footprint.</h2>
                <p>For developers who want a straightforward place to keep application files, with infrastructure they can understand.</p>
            </div>
            <div class="harbor-features">
                <article>
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M5 9h22v16H5zM5 9V5h9l4 4M10 15h12m-12 5h8" stroke="currentColor" stroke-width="1.6"/></svg>
                    <h3>Organize around objects</h3>
                    <p>Give files a bucket and a key. Keep uploads, documents, and other application assets behind one storage endpoint.</p>
                </article>
                <article>
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="m12 8-8 8 8 8m8-16 8 8-8 8M18 5l-4 22" stroke="currentColor" stroke-width="1.6"/></svg>
                    <h3>Connect with a familiar API</h3>
                    <p>Use path-style S3 requests for supported operations. Check the API docs for signing, examples, and compatibility before integrating.</p>
                </article>
                <article>
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M5 5h22v9H5zm0 13h22v9H5zM9 9h2m-2 13h2m5-13h7m-7 13h7" stroke="currentColor" stroke-width="1.6"/></svg>
                    <h3>Keep your hosting familiar</h3>
                    <p>A pure Laravel application with a cPanel deployment path. Your host’s storage, upload limits, and PHP configuration set the boundaries.</p>
                </article>
            </div>
            <aside class="harbor-compatibility" aria-labelledby="compatibility-title">
                <div><h3 id="compatibility-title">A focused S3 subset. Clear boundaries.</h3><p>Harbor supports a subset of the S3 API, not the complete AWS S3 service. Confirm the operations your application needs in the compatibility reference.</p></div>
                <a class="harbor-text-link" href="{{ route('api.docs') }}">Read the API docs <span aria-hidden="true">↗</span></a>
            </aside>
        </div>
    </section>

    <section class="harbor-container harbor-next" aria-labelledby="next-title">
        <div><p class="harbor-kicker">From endpoint to application</p><h2 id="next-title">Make room for<br>what you’re building.</h2></div>
        <div class="harbor-next-copy"><p>Start with the request format and supported operations. Already managing this server? Head to the owner console.</p><div class="harbor-actions"><a class="harbor-button" href="{{ route('api.docs') }}">Read the API docs</a><a class="harbor-text-link" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Owner console <span aria-hidden="true">↗</span></a></div></div>
    </section>
@endsection
