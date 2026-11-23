import { nodeZipReader, readZipWithYauzl } from './zip/nodeZip'
import { setDefaultZipReader } from './pipeline'

setDefaultZipReader(nodeZipReader)

export * from './index'
export { nodeZipReader, readZipWithYauzl }
