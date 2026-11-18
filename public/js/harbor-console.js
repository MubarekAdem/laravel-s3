(() => {
    const announce = (message) => {
        const toast = document.querySelector("[data-toast]");
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        clearTimeout(announce.timeout);
        announce.timeout = setTimeout(() => {
            toast.hidden = true;
        }, 2500);
    };
    const sidebar = document.getElementById("console-sidebar");
    const mobile = window.matchMedia("(max-width: 760px)");
    const toggleNavigation = (open, moveFocus = true) => {
        document.body.classList.toggle("nav-open", open);
        document
            .querySelector("[data-nav-toggle]")
            ?.setAttribute("aria-expanded", String(open));
        if (sidebar) {
            sidebar.inert = mobile.matches && !open;
            if (sidebar.inert) sidebar.setAttribute("aria-hidden", "true");
            else sidebar.removeAttribute("aria-hidden");
        }
        if (mobile.matches && moveFocus) {
            if (open) sidebar?.querySelector("a, button")?.focus();
            else document.querySelector("[data-nav-toggle]")?.focus();
        }
    };
    toggleNavigation(false, false);
    mobile.addEventListener("change", () => toggleNavigation(false, false));
    document
        .querySelector("[data-nav-toggle]")
        ?.addEventListener("click", () =>
            toggleNavigation(!document.body.classList.contains("nav-open")),
        );
    document
        .querySelector("[data-nav-close]")
        ?.addEventListener("click", () => toggleNavigation(false));
    document.addEventListener("keydown", (event) => {
        if (
            event.key === "Escape" &&
            document.body.classList.contains("nav-open")
        )
            toggleNavigation(false);
        if (
            event.key === "Tab" &&
            mobile.matches &&
            document.body.classList.contains("nav-open")
        ) {
            const controls = [
                ...sidebar.querySelectorAll("a[href],button:not([disabled])"),
            ];
            const first = controls[0],
                last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
    document.querySelectorAll("[data-open-dialog]").forEach((button) =>
        button.addEventListener("click", () => {
            const dialog = document.getElementById(button.dataset.openDialog);
            if (dialog instanceof HTMLDialogElement) dialog.showModal();
        }),
    );
    document
        .querySelectorAll("[data-close-dialog]")
        .forEach((button) =>
            button.addEventListener("click", () =>
                button.closest("dialog")?.close(),
            ),
        );
    document.querySelectorAll("dialog").forEach((dialog) =>
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
                const box = dialog.getBoundingClientRect();
                if (
                    event.clientX < box.left ||
                    event.clientX > box.right ||
                    event.clientY < box.top ||
                    event.clientY > box.bottom
                )
                    dialog.close();
            }
        }),
    );
    document.querySelectorAll("[data-copy]").forEach((button) =>
        button.addEventListener("click", async () => {
            const target = document.getElementById(button.dataset.copy);
            if (!target) return;
            try {
                await navigator.clipboard.writeText(target.textContent.trim());
                announce("Copied to clipboard");
            } catch {
                const selection = window.getSelection();
                const range = document.createRange();
                range.selectNodeContents(target);
                selection.removeAllRanges();
                selection.addRange(range);
                announce("Select and copy the highlighted text");
            }
        }),
    );
    const reopened = document.querySelector("dialog[data-reopen]");
    if (reopened) {
        const errors = document.querySelector('[role="alert"]');
        if (errors)
            reopened
                .querySelector(".dialog-body")
                ?.prepend(errors.cloneNode(true));
        reopened.showModal();
    }
})();
