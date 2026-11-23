import {
  handleDocxWorkerRequest,
  type DocxWorkerRequest,
  type DocxWorkerResponse,
} from '@vaulter/editor-pro-docx/browser'

interface WorkerScopeLike {
  addEventListener: (type: 'message', listener: (event: MessageEvent<DocxWorkerRequest>) => void) => void
  postMessage: (message: DocxWorkerResponse, transfer?: Transferable[]) => void
}

export function attachDocxWorkerListener(scope: WorkerScopeLike): void {
  scope.addEventListener('message', (event: MessageEvent<DocxWorkerRequest>) => {
    void handleDocxWorkerRequest(event.data).then((response) => {
      if (
        response.ok &&
        'binary' in response.result &&
        response.result.binary instanceof Uint8Array
      ) {
        scope.postMessage(response, [response.result.binary.buffer])
      } else {
        scope.postMessage(response)
      }
    })
  })
}

if (typeof self !== 'undefined' && typeof (self as unknown as WorkerScopeLike).postMessage === 'function') {
  attachDocxWorkerListener(self as unknown as WorkerScopeLike)
}
