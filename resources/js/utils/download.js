import api from "~/utils/api";

/**
 * Opening an API URL in a new tab loses the bearer token, so the file is
 * fetched with the session's credentials and handed to the browser as a blob.
 */
export async function downloadFile(url, filename, type) {
    const response = await api.get(url, { responseType: "blob" });
    const blob = new Blob([response.data], {
        type: type ?? response.headers?.["content-type"] ?? "application/octet-stream",
    });
    const href = URL.createObjectURL(blob);

    const link = document.createElement("a");
    link.href = href;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    // Give the download a moment to start before the URL is reclaimed.
    setTimeout(() => URL.revokeObjectURL(href), 10000);
}

export function openPdf(url, filename) {
    return downloadFile(url, filename, "application/pdf");
}
