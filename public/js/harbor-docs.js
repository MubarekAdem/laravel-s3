// Build highlighted text nodes so examples remain safe and copy verbatim.
const syntaxPattern =
    /(?<comment><!--[\s\S]*?-->|#[^\n]*)|(?<string>"(?:\\.|[^"\\])*"|'[^']*')|(?<tag><\/?[\w:-]+[^>]*>)|(?<variable>\$\{[^}\n]+\}|\$[A-Za-z_]\w*|\b[A-Z][A-Z0-9_]*(?==))|(?<option>--[a-z][\w-]*)|(?<command>\b(?:s3|curl|export|printf)\b)|(?<keyword>\b(?:GET|HEAD|PUT|POST|DELETE|AWS4-HMAC-SHA256|UNSIGNED-PAYLOAD|Authorization|Host)\b|x-amz-[\w-]+)|(?<number>\b\d+(?:[-.:]\d+)*\b)/g;
document.querySelectorAll(".docs-code pre code").forEach((code) => {
    const source = code.textContent;
    const fragment = document.createDocumentFragment();
    let offset = 0;
    for (const match of source.matchAll(syntaxPattern)) {
        fragment.append(
            document.createTextNode(source.slice(offset, match.index)),
        );
        const token = document.createElement("span");
        const kind = Object.keys(match.groups).find(
            (name) => match.groups[name] !== undefined,
        );
        token.className = "code-" + kind;
        token.textContent = match[0];
        fragment.append(token);
        offset = match.index + match[0].length;
    }
    fragment.append(document.createTextNode(source.slice(offset)));
    code.replaceChildren(fragment);
    code.dataset.highlighted = "true";
});

const sidebar = document.querySelector("#docs-sidebar");
const toggle = document.querySelector("[data-docs-toggle]");
const search = document.querySelector("#docs-search");
const status = document.querySelector("#docs-search-status");
const links = [...document.querySelectorAll(".docs-nav a")];
const sections = [...document.querySelectorAll(".docs-main section")];
function closeNavigation() {
    sidebar.classList.remove("is-open");
    toggle.setAttribute("aria-expanded", "false");
}
toggle.addEventListener("click", () => {
    const open = sidebar.classList.toggle("is-open");
    toggle.setAttribute("aria-expanded", String(open));
    if (open) search.focus();
});
links.forEach((link) =>
    link.addEventListener("click", () => {
        closeNavigation();
        if (innerWidth <= 800)
            document
                .querySelector(link.hash)
                ?.querySelector("h1,h2")
                ?.focus({ preventScroll: true });
    }),
);
sections.forEach((section) => {
    const heading = section.querySelector("h1,h2");
    if (heading) heading.tabIndex = -1;
});
search.addEventListener("input", () => {
    const query = search.value.trim().toLowerCase();
    let count = 0;
    links.forEach((link) => {
        const section = document.querySelector(link.hash);
        const match =
            !query || section.textContent.toLowerCase().includes(query);
        link.hidden = !match;
        if (match) count++;
    });
    status.textContent = query
        ? count
            ? count + " matching sections"
            : "No sections found. Try another term."
        : "";
});
document.addEventListener("keydown", (event) => {
    if (
        event.key === "/" &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        !event.target.closest("input,textarea,select,[contenteditable]")
    ) {
        event.preventDefault();
        if (innerWidth <= 800) {
            sidebar.classList.add("is-open");
            toggle.setAttribute("aria-expanded", "true");
        }
        search.focus();
    }
    if (event.key === "Escape") {
        if (search.value) {
            search.value = "";
            search.dispatchEvent(new Event("input"));
        } else if (sidebar.classList.contains("is-open")) {
            closeNavigation();
            toggle.focus();
        }
    }
});
document.querySelectorAll("[data-copy]").forEach((button) =>
    button.addEventListener("click", async () => {
        const code = button.parentElement.querySelector("code");
        try {
            await navigator.clipboard.writeText(code.textContent);
            button.textContent = "Copied";
        } catch {
            const range = document.createRange();
            range.selectNodeContents(code);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            button.textContent = "Code selected";
        }
        setTimeout(() => {
            button.textContent = "Copy";
        }, 2000);
    }),
);
if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
        (entries) =>
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                links.forEach((link) => {
                    if (link.hash === "#" + entry.target.id)
                        link.setAttribute("aria-current", "location");
                    else link.removeAttribute("aria-current");
                });
            }),
        { rootMargin: "-12% 0px -65% 0px" },
    );
    sections.forEach((section) => observer.observe(section));
}
